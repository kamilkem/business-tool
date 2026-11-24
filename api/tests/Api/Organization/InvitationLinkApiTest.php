<?php

declare(strict_types=1);

namespace App\Tests\Api\Organization;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\PasswordToken;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/members/{userId}/invitation-link (ADR 0037): an invitation link for the owner or manager to
 * pass on themselves, with the same rules as resending the invitation e-mail, and no e-mail.
 */
final class InvitationLinkApiTest extends ApiTestCase
{
    public function test_it_returns_a_new_link_that_sets_the_password_and_replaces_the_e_mailed_one(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $userId = static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak', 'role' => 'employee',
        ]])->toArray()['userId'];
        $emailedSecret = self::secretFromEmail(self::queuedEmail());

        $response = static::clientFor($owner, $organization)->request('POST', '/api/members/'.$userId.'/invitation-link');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertSame(['link', 'validForDays'], array_keys($body));
        self::assertSame(7, $body['validForDays']);
        self::assertMatchesRegularExpression('~^http://localhost:5174/set-password#token=[A-Za-z0-9_-]{43}$~', $body['link'], 'An employee\'s link opens the time clock.');
        self::assertQueuedEmailCount(0, message: 'Creating the link queues no e-mail.');

        self::setPassword($emailedSecret);
        self::assertResponseStatusCodeSame(404, 'The e-mailed link stopped working.');
        self::setPassword(explode('#token=', $body['link'])[1]);
        self::assertResponseStatusCodeSame(204);
    }

    public function test_it_links_owners_and_managers_to_the_admin_panel(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = UserFactory::new()->invited()->create();
        OrganizationMother::addMember($organization, $manager, MembershipRole::Manager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $link = static::clientFor($owner, $organization)
            ->request('POST', '/api/members/'.$manager->getId()->toRfc4122().'/invitation-link')
            ->toArray()['link'];

        self::assertStringStartsWith('http://localhost:5173/set-password#token=', $link);
    }

    /** @return iterable<string, array{MembershipRole, MembershipRole, int}> */
    public static function authorization(): iterable
    {
        yield 'owner for an owner' => [MembershipRole::Owner, MembershipRole::Owner, 200];
        yield 'owner for a manager' => [MembershipRole::Owner, MembershipRole::Manager, 200];
        yield 'owner for an employee' => [MembershipRole::Owner, MembershipRole::Employee, 200];
        yield 'manager for a manager' => [MembershipRole::Manager, MembershipRole::Manager, 403];
        yield 'manager for an employee' => [MembershipRole::Manager, MembershipRole::Employee, 200];
        yield 'employee for an employee' => [MembershipRole::Employee, MembershipRole::Employee, 403];
    }

    #[DataProvider('authorization')]
    public function test_it_authorizes_creating_a_link_by_role(MembershipRole $actorRole, MembershipRole $inviteeRole, int $expectedStatus): void
    {
        [$actor, $organization] = self::memberWithRole($actorRole);
        $invited = UserFactory::new()->invited()->create();
        OrganizationMother::addMember($organization, $invited, $inviteeRole);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $response = static::clientFor($actor, $organization)->request('POST', '/api/members/'.$invited->getId()->toRfc4122().'/invitation-link');

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame(200 === $expectedStatus ? 1 : 0, self::tokenCount());
        if (200 !== $expectedStatus) {
            self::assertStringNotContainsString('set-password', $response->getContent(false));
        }
        self::assertQueuedEmailCount(0);
    }

    public function test_it_refuses_a_member_who_already_has_a_password(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = UserFactory::createOne();
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$employee->getId()->toRfc4122().'/invitation-link');

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, self::tokenCount());
    }

    public function test_it_refuses_a_deactivated_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $invited = UserFactory::new()->invited()->create();
        OrganizationMother::addMember($organization, $invited, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $userId = $invited->getId()->toRfc4122();
        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$userId.'/deactivate');
        self::assertResponseStatusCodeSame(204);

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$userId.'/invitation-link');

        self::assertResponseStatusCodeSame(409);
    }

    public function test_it_hides_another_organizations_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $otherInvited = UserFactory::new()->invited()->create();
        OrganizationMother::addMember($other, $otherInvited, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$otherInvited->getId()->toRfc4122().'/invitation-link');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, self::tokenCount());
    }

    public function test_it_refuses_an_unknown_or_malformed_user_id(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.Uuid::v7()->toRfc4122().'/invitation-link');
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('POST', '/api/members/not-a-uuid/invitation-link');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_requires_authentication(): void
    {
        $invited = UserFactory::new()->invited()->create();

        static::createClient()->request('POST', '/api/members/'.$invited->getId()->toRfc4122().'/invitation-link', [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    private static function queuedEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private static function secretFromEmail(Email $email): string
    {
        self::assertSame(1, preg_match('~/set-password#token=([A-Za-z0-9_-]+)"~', (string) $email->getHtmlBody(), $matches));

        return $matches[1];
    }

    private static function setPassword(string $secret): void
    {
        static::createClient()->request('POST', '/api/auth/set-password', [
            'json' => ['token' => $secret, 'password' => 'correct horse battery'],
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    private static function tokenCount(): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(PasswordToken::class)->count();
    }
}
