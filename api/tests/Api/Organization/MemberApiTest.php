<?php

declare(strict_types=1);

namespace App\Tests\Api\Organization;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class MemberApiTest extends ApiTestCase
{
    public function test_it_lists_the_members_of_the_selected_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $invited = UserFactory::new()->invited()->create(['email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak']);
        OrganizationMother::addMember($organization, $invited, MembershipRole::Employee);
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
        OrganizationMother::addMember($other, $otherEmployee, MembershipRole::Employee);
        // A person working for both organizations is listed once, with this organization's role.
        $shared = UserFactory::createOne();
        OrganizationMother::addMember($organization, $shared, MembershipRole::Employee);
        OrganizationMother::addMember($other, $shared, MembershipRole::Manager);
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

    public function test_it_adds_a_person_without_an_account_as_an_invited_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $data = static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => ' Eve.Nowak@Example.com',
            'firstName' => 'Eve',
            'lastName' => 'Nowak',
            'role' => 'employee',
        ]])->toArray();

        self::assertResponseStatusCodeSame(201);
        $user = self::userByEmail('eve.nowak@example.com');
        self::assertNotNull($user);
        self::assertFalse($user->hasPassword());
        self::assertSame([
            'userId' => $user->getId()->toRfc4122(),
            'email' => 'eve.nowak@example.com',
            'firstName' => 'Eve',
            'lastName' => 'Nowak',
            'role' => 'employee',
            'joinedAt' => $data['joinedAt'],
            'invitationPending' => true,
        ], $data);
        self::assertContains($user->getId()->toRfc4122(), array_column(
            static::clientFor($owner, $organization)->request('GET', '/api/members')->toArray(),
            'userId',
        ));
    }

    public function test_it_adds_an_existing_user_without_changing_their_name_or_password(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $existing = UserFactory::createOne(['email' => 'max@example.com', 'firstName' => 'Max', 'lastName' => 'Adamski']);
        $passwordHash = $existing->getPassword();

        $data = static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'MAX@example.com',
            'firstName' => 'Maksymilian',
            'lastName' => 'Typed-By-The-Owner',
            'role' => 'manager',
        ]])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [$existing->getId()->toRfc4122(), 'max@example.com', 'Max', 'Adamski', 'manager', false],
            [$data['userId'], $data['email'], $data['firstName'], $data['lastName'], $data['role'], $data['invitationPending']],
        );
        $user = self::userByEmail('max@example.com');
        self::assertNotNull($user);
        self::assertSame([$passwordHash, 'Max', 'Adamski'], [$user->getPassword(), $user->getFirstName(), $user->getLastName()]);
        self::assertSame(2, self::userCount());
    }

    public function test_it_refuses_a_person_who_is_already_a_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => $owner->getEmail(),
            'firstName' => 'Olivia',
            'lastName' => 'Owner',
            'role' => 'employee',
        ]]);

        self::assertResponseStatusCodeSame(409);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidAdditions(): iterable
    {
        $valid = ['email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak', 'role' => 'employee'];

        yield 'missing e-mail' => [array_diff_key($valid, ['email' => true]), 'email'];
        yield 'invalid e-mail' => [['email' => 'not-an-email'] + $valid, 'email'];
        yield 'too long e-mail' => [['email' => str_repeat('a', 170).'@example.com'] + $valid, 'email'];
        yield 'blank first name' => [['firstName' => ''] + $valid, 'firstName'];
        yield 'whitespace-only first name' => [['firstName' => '   '] + $valid, 'firstName'];
        yield 'too long last name' => [['lastName' => str_repeat('a', 101)] + $valid, 'lastName'];
        yield 'missing role' => [array_diff_key($valid, ['role' => true]), 'role'];
        yield 'unknown role' => [['role' => 'admin'] + $valid, 'role'];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidAdditions')]
    public function test_it_validates_the_addition(array $payload, string $invalidField): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $data = static::clientFor($owner, $organization)
            ->request('POST', '/api/members', ['json' => $payload])
            ->toArray(throw: false);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($invalidField, array_column($data['violations'] ?? [], 'propertyPath'));
        self::assertSame(1, self::userCount());
    }

    /** @return iterable<string, array{MembershipRole, string, int}> */
    public static function addAuthorization(): iterable
    {
        yield 'owner adds an owner' => [MembershipRole::Owner, 'owner', 201];
        yield 'owner adds a manager' => [MembershipRole::Owner, 'manager', 201];
        yield 'owner adds an employee' => [MembershipRole::Owner, 'employee', 201];
        yield 'manager adds an owner' => [MembershipRole::Manager, 'owner', 403];
        yield 'manager adds a manager' => [MembershipRole::Manager, 'manager', 403];
        yield 'manager adds an employee' => [MembershipRole::Manager, 'employee', 201];
        yield 'employee adds an owner' => [MembershipRole::Employee, 'owner', 403];
        yield 'employee adds a manager' => [MembershipRole::Employee, 'manager', 403];
        yield 'employee adds an employee' => [MembershipRole::Employee, 'employee', 403];
    }

    #[DataProvider('addAuthorization')]
    public function test_it_authorizes_the_addition_by_role(MembershipRole $actorRole, string $role, int $expectedStatus): void
    {
        [$actor, $organization] = self::memberWithRole($actorRole);
        $usersBefore = self::userCount();

        static::clientFor($actor, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'new@example.com',
            'firstName' => 'New',
            'lastName' => 'Person',
            'role' => $role,
        ]]);

        self::assertResponseStatusCodeSame($expectedStatus);
        // A refused request leaves no user behind: the permission is checked before the user is created.
        self::assertSame(201 === $expectedStatus ? $usersBefore + 1 : $usersBefore, self::userCount());
    }

    public function test_it_adds_to_the_selected_organization_only(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        // A manager elsewhere is added here as an employee; their other membership is untouched and not shown.
        $shared = UserFactory::createOne();
        OrganizationMother::addMember($other, $shared, MembershipRole::Manager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $data = static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => $shared->getEmail(),
            'firstName' => 'Ignored',
            'lastName' => 'Ignored',
            'role' => 'employee',
        ]])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('employee', $data['role']);
        $otherOwner = OrganizationMother::ownerOf($other);
        $otherMembers = static::clientFor($otherOwner, $other)->request('GET', '/api/members')->toArray();
        self::assertSame('manager', array_column($otherMembers, 'role', 'userId')[$shared->getId()->toRfc4122()]);
        self::assertNotContains($owner->getId()->toRfc4122(), array_column($otherMembers, 'userId'));
    }

    public function test_it_refuses_an_addition_to_an_organization_the_actor_does_not_belong_to(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $usersBefore = self::userCount();

        static::clientFor($owner, $other)->request('POST', '/api/members', ['json' => [
            'email' => 'new@example.com',
            'firstName' => 'New',
            'lastName' => 'Person',
            'role' => 'employee',
        ]]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($usersBefore, self::userCount());
    }

    public function test_it_requires_a_selected_organization_to_add(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner)->request('POST', '/api/members', ['json' => [
            'email' => 'new@example.com',
            'firstName' => 'New',
            'lastName' => 'Person',
            'role' => 'employee',
        ]]);

        self::assertResponseStatusCodeSame(400);
        self::assertNull(self::userByEmail('new@example.com'));
    }

    public function test_it_requires_authentication_to_add(): void
    {
        static::createClient()->request('POST', '/api/members', [
            'headers' => ['Accept' => 'application/json'],
            'json' => ['email' => 'new@example.com', 'firstName' => 'New', 'lastName' => 'Person', 'role' => 'employee'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    private static function userByEmail(string $email): ?User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private static function userCount(): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->count();
    }
}
