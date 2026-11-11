<?php

declare(strict_types=1);

namespace App\Tests\Api\Organization;

use App\Organization\Domain\Model\Membership;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Ui\Http\Listener\SelectOrganizationFromHeaderListener;

/** POST /members/{userId}/deactivate and /reactivate, and what a deactivated membership no longer gives. */
final class MemberDeactivationApiTest extends ApiTestCase
{
    public function test_it_deactivates_a_member_and_keeps_the_membership(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);

        $response = self::act('deactivate', $owner, $organization, $employee->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        $membership = self::membershipIn($organization, $employee);
        self::assertNotNull($membership);
        self::assertNotNull($membership->getDeactivatedAt());
        self::assertSame(MembershipRole::Employee, $membership->getRole());
    }

    public function test_it_hides_deactivated_members_from_the_list_unless_asked(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::act('deactivate', $owner, $organization, $employee->getId()->toRfc4122());

        $active = static::clientFor($owner, $organization)->request('GET', '/api/members')->toArray();
        self::assertSame([$owner->getId()->toRfc4122()], array_column($active, 'userId'));
        self::assertNull($active[0]['deactivatedAt']);

        $all = static::clientFor($owner, $organization)->request('GET', '/api/members?includeDeactivated=true')->toArray();
        $byUserId = array_column($all, null, 'userId');
        self::assertCount(2, $byUserId);
        $deactivatedAt = $byUserId[$employee->getId()->toRfc4122()]['deactivatedAt'];
        self::assertIsString($deactivatedAt);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $deactivatedAt));

        $explicitlyActive = static::clientFor($owner, $organization)->request('GET', '/api/members?includeDeactivated=false')->toArray();
        self::assertCount(1, $explicitlyActive);
    }

    public function test_it_refuses_the_organization_to_a_deactivated_member_whose_token_was_issued_before(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($manager);

        self::requestWithToken($token, '/api/members', $organization);
        self::assertResponseIsSuccessful();

        self::act('deactivate', $owner, $organization, $manager->getId()->toRfc4122());

        self::requestWithToken($token, '/api/members', $organization);
        self::assertResponseStatusCodeSame(403);
        self::requestWithToken($token, '/api/me', $organization);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_drops_the_organization_from_a_deactivated_members_own_lists(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$employee, $other] = self::memberWithRole(MembershipRole::Employee);
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::act('deactivate', $owner, $organization, $employee->getId()->toRfc4122());

        $me = static::clientFor($employee)->request('GET', '/api/me')->toArray();
        self::assertSame([$other->getId()->toRfc4122()], array_column(array_column($me['memberships'], 'organization'), 'id'));
        $organizations = static::clientFor($employee)->request('GET', '/api/organizations')->toArray();
        self::assertSame([$other->getId()->toRfc4122()], array_column($organizations, 'id'));
    }

    /** @return iterable<string, array{MembershipRole, MembershipRole, int}> */
    public static function deactivationAuthorization(): iterable
    {
        yield 'owner deactivates an owner' => [MembershipRole::Owner, MembershipRole::Owner, 204];
        yield 'owner deactivates a manager' => [MembershipRole::Owner, MembershipRole::Manager, 204];
        yield 'owner deactivates an employee' => [MembershipRole::Owner, MembershipRole::Employee, 204];
        yield 'manager deactivates an owner' => [MembershipRole::Manager, MembershipRole::Owner, 403];
        yield 'manager deactivates a manager' => [MembershipRole::Manager, MembershipRole::Manager, 403];
        yield 'manager deactivates an employee' => [MembershipRole::Manager, MembershipRole::Employee, 204];
        yield 'employee deactivates an employee' => [MembershipRole::Employee, MembershipRole::Employee, 403];
    }

    #[DataProvider('deactivationAuthorization')]
    public function test_it_authorizes_the_deactivation_by_role(MembershipRole $actorRole, MembershipRole $targetRole, int $expectedStatus): void
    {
        [$actor, $organization] = self::memberWithRole($actorRole);
        $target = self::addMember($organization, $targetRole);

        self::act('deactivate', $actor, $organization, $target->getId()->toRfc4122());

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame(204 !== $expectedStatus, self::membershipIn($organization, $target)?->isActive());
    }

    public function test_it_refuses_to_deactivate_the_last_owner(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addMember($organization, MembershipRole::Manager);

        self::act('deactivate', $owner, $organization, $owner->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(409);
        self::assertTrue(self::membershipIn($organization, $owner)?->isActive());
    }

    public function test_it_refuses_to_deactivate_the_last_active_owner_when_another_owner_is_deactivated(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $coOwner = self::addMember($organization, MembershipRole::Owner);
        self::act('deactivate', $owner, $organization, $coOwner->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);

        self::act('deactivate', $owner, $organization, $owner->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(409);
    }

    public function test_it_lets_an_owner_deactivate_themselves_while_another_owner_remains(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addMember($organization, MembershipRole::Owner);

        self::act('deactivate', $owner, $organization, $owner->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        static::clientFor($owner, $organization)->request('GET', '/api/members');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_changes_nothing_when_deactivating_a_deactivated_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::act('deactivate', $owner, $organization, $employee->getId()->toRfc4122());
        $deactivatedAt = self::membershipIn($organization, $employee)?->getDeactivatedAt();

        self::act('deactivate', $owner, $organization, $employee->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertEquals($deactivatedAt, self::membershipIn($organization, $employee)?->getDeactivatedAt());
    }

    /** @return iterable<string, array{string}> */
    public static function actions(): iterable
    {
        yield 'deactivate' => ['deactivate'];
        yield 'reactivate' => ['reactivate'];
    }

    #[DataProvider('actions')]
    public function test_it_refuses_an_unknown_or_malformed_user_id(string $action): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::act($action, $owner, $organization, Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        self::act($action, $owner, $organization, 'not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('actions')]
    public function test_it_hides_another_organizations_member(string $action): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $otherEmployee = self::addMember($other, MembershipRole::Employee);
        if ('reactivate' === $action) {
            OrganizationMother::deactivateMember($other, $otherEmployee);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        self::act($action, $owner, $organization, $otherEmployee->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        self::assertSame('reactivate' !== $action, self::membershipIn($other, $otherEmployee)?->isActive());
    }

    public function test_it_deactivates_in_the_selected_organization_only(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$shared, $other] = self::memberWithRole(MembershipRole::Manager);
        OrganizationMother::addMember($organization, $shared, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::act('deactivate', $owner, $organization, $shared->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertFalse(self::membershipIn($organization, $shared)?->isActive());
        self::assertTrue(self::membershipIn($other, $shared)?->isActive());
        static::clientFor($shared, $other)->request('GET', '/api/members');
        self::assertResponseIsSuccessful();
    }

    #[DataProvider('actions')]
    public function test_it_refuses_an_organization_the_actor_does_not_belong_to(string $action): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $otherEmployee = self::addMember($other, MembershipRole::Employee);

        self::act($action, $owner, $other, $otherEmployee->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
        self::assertTrue(self::membershipIn($other, $otherEmployee)?->isActive());
    }

    #[DataProvider('actions')]
    public function test_it_requires_a_selected_organization(string $action): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner)->request('POST', '/api/members/'.$owner->getId()->toRfc4122().'/'.$action);

        self::assertResponseStatusCodeSame(400);
    }

    #[DataProvider('actions')]
    public function test_it_requires_authentication(string $action): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::createClient()->request('POST', '/api/members/'.$owner->getId()->toRfc4122().'/'.$action, [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_it_invalidates_a_pending_invitation(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $userId = static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak', 'role' => 'employee',
        ]])->toArray()['userId'];
        $secret = self::secretFrom(self::queuedEmail());

        self::act('deactivate', $owner, $organization, $userId);
        self::assertResponseStatusCodeSame(204);

        static::createClient()->request('POST', '/api/auth/set-password', [
            'json' => ['token' => $secret, 'password' => 'correct horse battery'],
            'headers' => ['Accept' => 'application/json'],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_refuses_to_add_a_deactivated_member_again(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::act('deactivate', $owner, $organization, $employee->getId()->toRfc4122());

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => $employee->getEmail(), 'firstName' => 'Eve', 'lastName' => 'Nowak', 'role' => 'manager',
        ]]);

        self::assertResponseStatusCodeSame(409);
        self::assertQueuedEmailCount(0);
        self::assertFalse(self::membershipIn($organization, $employee)?->isActive());
    }

    public function test_it_refuses_to_change_the_role_of_a_deactivated_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::act('deactivate', $owner, $organization, $employee->getId()->toRfc4122());

        static::clientFor($owner, $organization)->request('PATCH', '/api/members/'.$employee->getId()->toRfc4122(), [
            'json' => ['role' => 'manager'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(MembershipRole::Employee, self::membershipIn($organization, $employee)?->getRole());
    }

    public function test_it_refuses_to_resend_an_invitation_to_a_deactivated_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $invited = UserFactory::new()->invited()->create();
        OrganizationMother::addMember($organization, $invited, MembershipRole::Employee);
        OrganizationMother::deactivateMember($organization, $invited);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$invited->getId()->toRfc4122().'/invitation');

        self::assertResponseStatusCodeSame(409);
        self::assertQueuedEmailCount(0);
    }

    public function test_it_reactivates_a_member_with_their_previous_role(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        self::act('deactivate', $owner, $organization, $manager->getId()->toRfc4122());

        $response = self::act('reactivate', $owner, $organization, $manager->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        $membership = self::membershipIn($organization, $manager);
        self::assertNotNull($membership);
        self::assertTrue($membership->isActive());
        self::assertSame(MembershipRole::Manager, $membership->getRole());
        static::clientFor($manager, $organization)->request('GET', '/api/members');
        self::assertResponseIsSuccessful();
    }

    #[DataProvider('deactivationAuthorization')]
    public function test_it_authorizes_the_reactivation_by_role(MembershipRole $actorRole, MembershipRole $targetRole, int $expectedStatus): void
    {
        [$actor, $organization] = self::memberWithRole($actorRole);
        $target = self::addMember($organization, $targetRole);
        OrganizationMother::deactivateMember($organization, $target);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::act('reactivate', $actor, $organization, $target->getId()->toRfc4122());

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame(204 === $expectedStatus, self::membershipIn($organization, $target)?->isActive());
    }

    public function test_it_validates_the_list_filter(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('GET', '/api/members?includeDeactivated=maybe');

        self::assertResponseStatusCodeSame(422);
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private static function act(string $action, User $actor, Organization $organization, string $userId): ResponseInterface
    {
        return static::clientFor($actor, $organization)->request('POST', '/api/members/'.$userId.'/'.$action);
    }

    private static function requestWithToken(string $token, string $uri, Organization $organization): void
    {
        static::createClient()->request('GET', $uri, ['headers' => [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            SelectOrganizationFromHeaderListener::HEADER => $organization->getId()->toRfc4122(),
        ]]);
    }

    /** The user's membership in the organization, read from the database. */
    private static function membershipIn(Organization $organization, User $user): ?Membership
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Membership::class)
            ->findOneBy(['organization' => $organization->getId(), 'user' => $user->getId()]);
    }

    private static function queuedEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    /** The secret from the link in an invitation e-mail, as the set-password page reads it. */
    private static function secretFrom(Email $email): string
    {
        self::assertSame(1, preg_match('~/set-password#token=([A-Za-z0-9_-]+)"~', (string) $email->getHtmlBody(), $matches));

        return $matches[1];
    }
}
