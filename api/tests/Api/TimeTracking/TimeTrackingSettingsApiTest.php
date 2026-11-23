<?php

declare(strict_types=1);

namespace App\Tests\Api\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** GET and PUT /time-tracking-settings (#33). The server's time is mocked at NOW (UTC). */
final class TimeTrackingSettingsApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-23 10:00:00';

    private const array DEFAULTS = ['maxShiftHours' => 12, 'updatedAt' => null];

    /** @return iterable<string, array{MembershipRole}> */
    public static function roles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
        yield 'employee' => [MembershipRole::Employee];
    }

    #[DataProvider('roles')]
    public function test_it_gives_every_active_member_the_defaults(MembershipRole $role): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $member = MembershipRole::Owner === $role ? OrganizationMother::ownerOf($organization) : self::addMember($organization, $role);

        self::assertSame(self::DEFAULTS, self::get($member, $organization)->toArray());
        self::assertResponseStatusCodeSame(200);
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_change_the_settings(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        $response = self::put($actor, $organization, ['maxShiftHours' => 10]);

        self::assertResponseStatusCodeSame(200);
        $expected = ['maxShiftHours' => 10, 'updatedAt' => '2026-11-23T10:00:00+00:00'];
        self::assertSame($expected, $response->toArray());
        self::assertSame($expected, self::get($employee, $organization)->toArray());

        self::mockTime('2026-11-24 08:00:00');
        self::put($actor, $organization, ['maxShiftHours' => 24]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['maxShiftHours' => 24, 'updatedAt' => '2026-11-24T08:00:00+00:00'], self::get($employee, $organization)->toArray());

        // Setting the current value changes nothing, not even the moment.
        self::mockTime('2026-11-25 08:00:00');
        self::assertSame(['maxShiftHours' => 24, 'updatedAt' => '2026-11-24T08:00:00+00:00'], self::put($actor, $organization, ['maxShiftHours' => 24])->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'empty' => [[]];
        yield 'zero' => [['maxShiftHours' => 0]];
        yield 'more than a day' => [['maxShiftHours' => 25]];
        yield 'a fraction' => [['maxShiftHours' => 10.5]];
        yield 'a string' => [['maxShiftHours' => '10']];
        yield 'null' => [['maxShiftHours' => null]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_it_answers_422_with_violations_for_invalid_settings(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::put($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['maxShiftHours'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame(self::DEFAULTS, self::get($owner, $organization)->toArray());
    }

    public function test_it_translates_violations_into_polish(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($owner, $organization)->request('PUT', '/api/time-tracking-settings', [
            'json' => ['maxShiftHours' => 30],
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('24', $response->toArray(false)['violations'][0]['message'] ?? '');
        self::assertStringContainsString('Ta wartość', $response->toArray(false)['violations'][0]['message'] ?? '');
    }

    public function test_it_refuses_changes_to_employees_with_a_code(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);

        foreach ([['maxShiftHours' => 10], ['maxShiftHours' => 99]] as $body) {
            $response = self::put($employee, $organization, $body);

            self::assertResponseStatusCodeSame(403);
            self::assertSame('time_tracking.settings_change_forbidden', $response->toArray(false)['code']);
        }
        self::assertSame(self::DEFAULTS, self::get($owner, $organization)->toArray());
    }

    public function test_it_refuses_deactivated_members_and_outsiders(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $outsider = UserFactory::createOne();

        foreach ([$formerManager, $outsider] as $user) {
            self::get($user, $organization);
            self::assertResponseStatusCodeSame(403);
            self::put($user, $organization, ['maxShiftHours' => 10]);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame(self::DEFAULTS, self::get($owner, $organization)->toArray());
    }

    public function test_it_keeps_each_organizations_settings_apart(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        // A member of both: what counts is the organization selected by the header.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::mockTime(self::NOW);

        self::put($ownerElsewhere, $elsewhere, ['maxShiftHours' => 8]);
        self::assertSame(self::DEFAULTS, self::get($owner, $organization)->toArray());

        self::put($owner, $organization, ['maxShiftHours' => 16]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(16, self::get($owner, $organization)->toArray()['maxShiftHours']);
        self::assertSame(8, self::get($owner, $elsewhere)->toArray()['maxShiftHours']);

        // The employee there may read its settings but not change them.
        self::put($owner, $elsewhere, ['maxShiftHours' => 12]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(8, self::get($ownerElsewhere, $elsewhere)->toArray()['maxShiftHours']);
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private static function get(User $user, Organization $organization): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/time-tracking-settings');
    }

    /** @param array<string, mixed> $body */
    private static function put(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PUT', '/api/time-tracking-settings', ['json' => $body]);
    }
}
