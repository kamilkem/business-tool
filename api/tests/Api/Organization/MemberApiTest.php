<?php

declare(strict_types=1);

namespace App\Tests\Api\Organization;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class MemberApiTest extends ApiTestCase
{
    public function test_it_lists_the_members_of_the_selected_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $invited = UserFactory::new()->invited()->create(['email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak']);
        $organization->addMember($invited, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $data = static::clientFor($owner, $organization)->request('GET', '/api/members')->toArray();

        self::assertResponseIsSuccessful();
        $byUserId = array_column($data, null, 'userId');
        self::assertCount(2, $byUserId);
        self::assertSame([
            'userId' => $invited->getId()->toRfc4122(),
            'email' => 'eve@example.com',
            'firstName' => 'Eve',
            'lastName' => 'Nowak',
            'role' => 'employee',
            'joinedAt' => $byUserId[$invited->getId()->toRfc4122()]['joinedAt'],
            'invitationPending' => true,
        ], $byUserId[$invited->getId()->toRfc4122()]);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $byUserId[$invited->getId()->toRfc4122()]['joinedAt']));
        self::assertSame(['owner', false], [
            $byUserId[$owner->getId()->toRfc4122()]['role'],
            $byUserId[$owner->getId()->toRfc4122()]['invitationPending'],
        ]);
    }

    public function test_it_hides_the_members_of_other_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $otherEmployee = UserFactory::createOne();
        $other->addMember($otherEmployee, MembershipRole::Employee);
        // A person working for both organizations is listed once, with this organization's role.
        $shared = UserFactory::createOne();
        $organization->addMember($shared, MembershipRole::Employee);
        $other->addMember($shared, MembershipRole::Manager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $data = static::clientFor($owner, $organization)->request('GET', '/api/members')->toArray();

        self::assertEqualsCanonicalizing(
            [$owner->getId()->toRfc4122(), $shared->getId()->toRfc4122()],
            array_column($data, 'userId'),
        );
        self::assertSame('employee', array_column($data, 'role', 'userId')[$shared->getId()->toRfc4122()]);
    }

    /** @return iterable<string, array{MembershipRole, int}> */
    public static function listAuthorization(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 200];
        yield 'manager' => [MembershipRole::Manager, 200];
        yield 'employee' => [MembershipRole::Employee, 403];
    }

    #[DataProvider('listAuthorization')]
    public function test_it_authorizes_the_list_by_role(MembershipRole $role, int $expectedStatus): void
    {
        [$user, $organization] = self::memberWithRole($role);

        static::clientFor($user, $organization)->request('GET', '/api/members');

        self::assertResponseStatusCodeSame($expectedStatus);
    }

    public function test_it_refuses_a_non_member_of_the_selected_organization(): void
    {
        $other = OrganizationFactory::createOne();

        static::clientFor(UserFactory::createOne(), $other)->request('GET', '/api/members');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_requires_a_selected_organization(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner)->request('GET', '/api/members');

        self::assertResponseStatusCodeSame(400);
    }

    public function test_it_requires_authentication(): void
    {
        static::createClient()->request('GET', '/api/members', ['headers' => ['Accept' => 'application/json']]);

        self::assertResponseStatusCodeSame(401);
    }
}
