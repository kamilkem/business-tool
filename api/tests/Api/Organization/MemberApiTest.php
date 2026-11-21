<?php

declare(strict_types=1);

namespace App\Tests\Api\Organization;

use App\Organization\Domain\Model\Membership;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Domain\Language\Language;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
            'deactivatedAt' => null,
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
            'deactivatedAt' => null,
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

    public function test_it_gives_a_new_user_the_language_the_inviter_chose(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'eve@example.com',
            'firstName' => 'Eve',
            'lastName' => 'Nowak',
            'role' => 'employee',
            'language' => 'pl',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(Language::Polish, self::userByEmail('eve@example.com')?->getLanguage());
    }

    public function test_it_leaves_a_new_users_language_unset_when_none_is_given(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'eve@example.com',
            'firstName' => 'Eve',
            'lastName' => 'Nowak',
            'role' => 'employee',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $user = self::userByEmail('eve@example.com');
        self::assertNotNull($user);
        self::assertNull($user->getLanguage());
    }

    public function test_it_keeps_an_existing_users_own_language(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $existing = UserFactory::createOne(['email' => 'max@example.com']);
        $existing->changeLanguage(Language::English);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'max@example.com',
            'firstName' => 'Max',
            'lastName' => 'Adamski',
            'role' => 'employee',
            'language' => 'pl',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(Language::English, self::userByEmail('max@example.com')?->getLanguage());
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
        yield 'unknown language' => [['language' => 'de'] + $valid, 'language'];
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

    public function test_it_queues_an_invitation_email_whose_link_sets_the_password(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak', 'role' => 'employee',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertQueuedEmailCount(1);
        $email = self::queuedEmail();
        self::assertEmailAddressContains($email, 'To', 'eve@example.com');
        self::assertEmailSubjectContains($email, 'You are invited to '.$organization->getName());
        self::assertEmailHtmlBodyContains($email, 'Hello Eve');
        self::assertEmailHtmlBodyContains($email, $owner->getFirstName().' '.$owner->getLastName().' invited you');
        // Employees work in the time clock (TIMECLOCK_URL in .env).
        self::assertEmailHtmlBodyContains($email, 'http://localhost:5174/set-password#token=');

        self::setPassword(self::secretFrom($email));

        self::assertResponseStatusCodeSame(204);
        self::assertTrue(self::userByEmail('eve@example.com')?->hasPassword());
    }

    public function test_it_writes_the_invitation_in_the_language_chosen_for_the_invitee(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak', 'role' => 'employee', 'language' => 'pl',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $email = self::queuedEmail();
        self::assertEmailSubjectContains($email, 'Zaproszenie do '.$organization->getName());
        self::assertEmailHtmlBodyContains($email, 'Cześć Eve,');
        self::assertEmailHtmlBodyContains($email, $owner->getFirstName().' '.$owner->getLastName().' zaprasza Cię do');
        self::assertEmailHtmlBodyContains($email, 'wygasa za 7 dni');
        self::assertEmailHtmlBodyNotContains($email, 'Hello');
    }

    public function test_it_writes_the_invitation_in_the_inviters_language_when_none_is_chosen(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $owner->changeLanguage(Language::Polish);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak', 'role' => 'employee',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertEmailSubjectContains(self::queuedEmail(), 'Zaproszenie do '.$organization->getName());
    }

    public function test_it_notifies_an_existing_user_in_their_own_language(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $existing = UserFactory::createOne(['email' => 'max@example.com', 'firstName' => 'Max']);
        $existing->changeLanguage(Language::Polish);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'max@example.com', 'firstName' => 'Max', 'lastName' => 'Adamski', 'role' => 'employee', 'language' => 'en',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $email = self::queuedEmail();
        self::assertEmailSubjectContains($email, 'Masz teraz dostęp do '.$organization->getName());
        self::assertEmailHtmlBodyContains($email, 'Zaloguj się');
    }

    public function test_it_links_an_invited_manager_to_the_admin_panel(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'max@example.com', 'firstName' => 'Max', 'lastName' => 'Adamski', 'role' => 'manager',
        ]]);

        self::assertEmailHtmlBodyContains(self::queuedEmail(), 'http://localhost:5173/set-password#token=');
    }

    public function test_it_notifies_an_existing_user_with_a_sign_in_link_and_issues_no_token(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        UserFactory::createOne(['email' => 'max@example.com', 'firstName' => 'Max']);

        static::clientFor($owner, $organization)->request('POST', '/api/members', ['json' => [
            'email' => 'max@example.com', 'firstName' => 'Max', 'lastName' => 'Adamski', 'role' => 'employee',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertQueuedEmailCount(1);
        $email = self::queuedEmail();
        self::assertEmailAddressContains($email, 'To', 'max@example.com');
        self::assertEmailSubjectContains($email, 'You now have access to '.$organization->getName());
        self::assertEmailHtmlBodyContains($email, 'http://localhost:5174/login');
        self::assertEmailHtmlBodyNotContains($email, 'set-password');
        self::assertSame(0, self::tokenCount());
    }

    public function test_it_resends_an_invitation_whose_new_link_replaces_the_old_one(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $client = static::clientFor($owner, $organization);
        $userId = $client->request('POST', '/api/members', ['json' => [
            'email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak', 'role' => 'employee',
        ]])->toArray()['userId'];
        $firstSecret = self::secretFrom(self::queuedEmail());

        $response = static::clientFor($owner, $organization)->request('POST', '/api/members/'.$userId.'/invitation');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertQueuedEmailCount(1);
        $email = self::queuedEmail();
        self::assertEmailAddressContains($email, 'To', 'eve@example.com');
        $secondSecret = self::secretFrom($email);
        self::assertNotSame($firstSecret, $secondSecret);

        self::setPassword($firstSecret);
        self::assertResponseStatusCodeSame(404);
        self::setPassword($secondSecret);
        self::assertResponseStatusCodeSame(204);
    }

    /** @return iterable<string, array{MembershipRole, MembershipRole, int}> */
    public static function resendAuthorization(): iterable
    {
        yield 'owner to an owner' => [MembershipRole::Owner, MembershipRole::Owner, 204];
        yield 'owner to a manager' => [MembershipRole::Owner, MembershipRole::Manager, 204];
        yield 'owner to an employee' => [MembershipRole::Owner, MembershipRole::Employee, 204];
        yield 'manager to a manager' => [MembershipRole::Manager, MembershipRole::Manager, 403];
        yield 'manager to an employee' => [MembershipRole::Manager, MembershipRole::Employee, 204];
        yield 'employee to an employee' => [MembershipRole::Employee, MembershipRole::Employee, 403];
    }

    #[DataProvider('resendAuthorization')]
    public function test_it_authorizes_resending_an_invitation_by_role(MembershipRole $actorRole, MembershipRole $inviteeRole, int $expectedStatus): void
    {
        [$actor, $organization] = self::memberWithRole($actorRole);
        $invited = UserFactory::new()->invited()->create();
        OrganizationMother::addMember($organization, $invited, $inviteeRole);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($actor, $organization)->request('POST', '/api/members/'.$invited->getId()->toRfc4122().'/invitation');

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertQueuedEmailCount(204 === $expectedStatus ? 1 : 0);
        self::assertSame(204 === $expectedStatus ? 1 : 0, self::tokenCount());
    }

    public function test_it_refuses_to_resend_to_a_member_who_already_has_a_password(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = UserFactory::createOne();
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$employee->getId()->toRfc4122().'/invitation');

        self::assertResponseStatusCodeSame(409);
        self::assertQueuedEmailCount(0);
    }

    public function test_it_refuses_to_resend_to_another_organizations_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $otherInvited = UserFactory::new()->invited()->create();
        OrganizationMother::addMember($other, $otherInvited, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$otherInvited->getId()->toRfc4122().'/invitation');

        self::assertResponseStatusCodeSame(404);
        self::assertQueuedEmailCount(0);
        self::assertSame(0, self::tokenCount());
    }

    public function test_it_refuses_to_resend_to_an_unknown_or_malformed_user_id(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner, $organization)->request('POST', '/api/members/'.Uuid::v7()->toRfc4122().'/invitation');
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('POST', '/api/members/not-a-uuid/invitation');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_requires_authentication_to_resend(): void
    {
        $invited = UserFactory::new()->invited()->create();

        static::createClient()->request('POST', '/api/members/'.$invited->getId()->toRfc4122().'/invitation', [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_it_changes_a_members_role(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = UserFactory::createOne(['email' => 'eve@example.com', 'firstName' => 'Eve', 'lastName' => 'Nowak']);
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $data = self::changeRole($owner, $organization, $employee->getId()->toRfc4122(), ['role' => 'manager'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame([
            'userId' => $employee->getId()->toRfc4122(),
            'email' => 'eve@example.com',
            'firstName' => 'Eve',
            'lastName' => 'Nowak',
            'role' => 'manager',
            'joinedAt' => $data['joinedAt'],
            'invitationPending' => false,
            'deactivatedAt' => null,
        ], $data);
        self::assertSame(MembershipRole::Manager, self::roleIn($organization, $employee));
    }

    public function test_it_keeps_the_role_when_a_patch_does_not_send_it(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = UserFactory::createOne();
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $data = self::changeRole($owner, $organization, $employee->getId()->toRfc4122(), [])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('employee', $data['role']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRoleChanges(): iterable
    {
        yield 'unknown role' => [['role' => 'admin']];
        yield 'null role' => [['role' => null]];
        yield 'role of the wrong type' => [['role' => 1]];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRoleChanges')]
    public function test_it_validates_the_role_change(array $payload): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = UserFactory::createOne();
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $data = self::changeRole($owner, $organization, $employee->getId()->toRfc4122(), $payload)->toArray(throw: false);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('role', array_column($data['violations'] ?? [], 'propertyPath'));
        self::assertSame(MembershipRole::Employee, self::roleIn($organization, $employee));
    }

    /** @return iterable<string, array{MembershipRole, MembershipRole, MembershipRole, int}> */
    public static function roleChangeAuthorization(): iterable
    {
        foreach (MembershipRole::cases() as $actor) {
            foreach (MembershipRole::cases() as $target) {
                foreach (MembershipRole::cases() as $role) {
                    $expectedStatus = match ($actor) {
                        MembershipRole::Owner => 200,
                        // Managers act on employees only and promote no one.
                        MembershipRole::Manager => MembershipRole::Employee === $target && MembershipRole::Employee === $role ? 200 : 403,
                        MembershipRole::Employee => 403,
                    };

                    yield \sprintf('%s makes a %s %s', $actor->value, $target->value, $role->value) => [$actor, $target, $role, $expectedStatus];
                }
            }
        }
    }

    #[DataProvider('roleChangeAuthorization')]
    public function test_it_authorizes_the_role_change_by_role(
        MembershipRole $actorRole,
        MembershipRole $targetRole,
        MembershipRole $role,
        int $expectedStatus,
    ): void {
        [$actor, $organization] = self::memberWithRole($actorRole);
        $target = UserFactory::createOne();
        OrganizationMother::addMember($organization, $target, $targetRole);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::changeRole($actor, $organization, $target->getId()->toRfc4122(), ['role' => $role->value]);

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame(200 === $expectedStatus ? $role : $targetRole, self::roleIn($organization, $target));
    }

    #[DataProvider('demotionRoles')]
    public function test_it_refuses_to_demote_the_last_owner(string $role): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::changeRole($owner, $organization, $owner->getId()->toRfc4122(), ['role' => $role]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(MembershipRole::Owner, self::roleIn($organization, $owner));
    }

    /** @return iterable<string, array{string}> */
    public static function demotionRoles(): iterable
    {
        yield 'to manager' => ['manager'];
        yield 'to employee' => ['employee'];
    }

    public function test_it_lets_an_owner_step_down_while_another_owner_remains(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $coOwner = UserFactory::createOne();
        OrganizationMother::addMember($organization, $coOwner, MembershipRole::Owner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $data = self::changeRole($owner, $organization, $owner->getId()->toRfc4122(), ['role' => 'employee'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame([$owner->getId()->toRfc4122(), 'employee'], [$data['userId'], $data['role']]);
        // Now an employee, they no longer manage the team; the remaining owner cannot step down.
        static::clientFor($owner, $organization)->request('GET', '/api/members');
        self::assertResponseStatusCodeSame(403);
        self::changeRole($coOwner, $organization, $coOwner->getId()->toRfc4122(), ['role' => 'manager']);
        self::assertResponseStatusCodeSame(409);
    }

    public function test_it_refuses_to_change_the_role_of_an_unknown_or_malformed_user_id(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::changeRole($owner, $organization, Uuid::v7()->toRfc4122(), ['role' => 'manager']);
        self::assertResponseStatusCodeSame(404);
        self::changeRole($owner, $organization, 'not-a-uuid', ['role' => 'manager']);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_refuses_to_change_the_role_of_another_organizations_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $otherEmployee = UserFactory::createOne();
        OrganizationMother::addMember($other, $otherEmployee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::changeRole($owner, $organization, $otherEmployee->getId()->toRfc4122(), ['role' => 'manager']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(MembershipRole::Employee, self::roleIn($other, $otherEmployee));
    }

    public function test_it_changes_the_role_in_the_selected_organization_only(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $shared = UserFactory::createOne();
        OrganizationMother::addMember($organization, $shared, MembershipRole::Employee);
        OrganizationMother::addMember($other, $shared, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::changeRole($owner, $organization, $shared->getId()->toRfc4122(), ['role' => 'manager']);

        self::assertResponseIsSuccessful();
        self::assertSame(MembershipRole::Manager, self::roleIn($organization, $shared));
        self::assertSame(MembershipRole::Employee, self::roleIn($other, $shared));
    }

    public function test_it_refuses_a_role_change_in_an_organization_the_actor_does_not_belong_to(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        $otherOwner = OrganizationMother::ownerOf($other);

        self::changeRole($owner, $other, $otherOwner->getId()->toRfc4122(), ['role' => 'employee']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(MembershipRole::Owner, self::roleIn($other, $otherOwner));
    }

    public function test_it_requires_a_selected_organization_to_change_a_role(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner)->request('PATCH', '/api/members/'.$owner->getId()->toRfc4122(), [
            'json' => ['role' => 'manager'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function test_it_requires_authentication_to_change_a_role(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::createClient()->request('PATCH', '/api/members/'.$owner->getId()->toRfc4122(), [
            'json' => ['role' => 'manager'],
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /** @param array<string, mixed> $payload */
    private static function changeRole(User $actor, Organization $organization, string $userId, array $payload): ResponseInterface
    {
        return static::clientFor($actor, $organization)->request('PATCH', '/api/members/'.$userId, [
            'json' => $payload,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
    }

    /** The user's role in the organization, read from the database. */
    private static function roleIn(Organization $organization, User $user): ?MembershipRole
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Membership::class)
            ->findOneBy(['organization' => $organization->getId(), 'user' => $user->getId()])
            ?->getRole();
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
