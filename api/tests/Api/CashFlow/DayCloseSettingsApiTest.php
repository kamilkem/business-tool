<?php

declare(strict_types=1);

namespace App\Tests\Api\CashFlow;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** GET and PUT /day-close-settings (ADR 0033 §5). The server's time is mocked at NOW (UTC). */
final class DayCloseSettingsApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private const array DEFAULTS = ['mode' => 'day_end_time', 'dayEndsAt' => '05:00', 'updatedAt' => null];

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

        $response = self::put($actor, $organization, ['mode' => 'employee_picks', 'dayEndsAt' => '04:30']);

        self::assertResponseStatusCodeSame(200);
        $expected = ['mode' => 'employee_picks', 'dayEndsAt' => '04:30', 'updatedAt' => '2026-11-22T10:00:00+00:00'];
        self::assertSame($expected, $response->toArray());
        self::assertSame($expected, self::get($employee, $organization)->toArray());

        // A second change updates the same settings.
        self::mockTime('2026-11-23 08:00:00');
        self::put($actor, $organization, ['mode' => 'day_end_time', 'dayEndsAt' => '12:00']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['mode' => 'day_end_time', 'dayEndsAt' => '12:00', 'updatedAt' => '2026-11-23T08:00:00+00:00'], self::get($employee, $organization)->toArray());

        // Setting the current values changes nothing, not even the moment.
        self::mockTime('2026-11-24 08:00:00');
        $unchanged = self::put($actor, $organization, ['mode' => 'day_end_time', 'dayEndsAt' => '12:00'])->toArray();
        self::assertSame(['mode' => 'day_end_time', 'dayEndsAt' => '12:00', 'updatedAt' => '2026-11-23T08:00:00+00:00'], $unchanged);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidBodies(): iterable
    {
        yield 'empty' => [[], ['dayEndsAt', 'mode']];
        yield 'no day-end time' => [['mode' => 'day_end_time'], ['dayEndsAt']];
        yield 'no mode' => [['dayEndsAt' => '05:00'], ['mode']];
        yield 'an unknown mode' => [['mode' => 'shift', 'dayEndsAt' => '05:00'], ['mode']];
        yield 'a null mode' => [['mode' => null, 'dayEndsAt' => '05:00'], ['mode']];
        yield 'after noon' => [['mode' => 'day_end_time', 'dayEndsAt' => '12:01'], ['dayEndsAt']];
        yield 'the evening' => [['mode' => 'day_end_time', 'dayEndsAt' => '22:00'], ['dayEndsAt']];
        yield 'one digit for the hour' => [['mode' => 'day_end_time', 'dayEndsAt' => '5:00'], ['dayEndsAt']];
        yield 'with seconds' => [['mode' => 'day_end_time', 'dayEndsAt' => '05:00:00'], ['dayEndsAt']];
        yield 'blank' => [['mode' => 'day_end_time', 'dayEndsAt' => ''], ['dayEndsAt']];
        yield 'a number' => [['mode' => 'day_end_time', 'dayEndsAt' => 5], ['dayEndsAt']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $paths
     */
    #[DataProvider('invalidBodies')]
    public function test_it_answers_422_with_violations_for_invalid_settings(array $body, array $paths): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::put($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, self::violationPaths($response));
        self::assertSame(self::DEFAULTS, self::get($owner, $organization)->toArray());
    }

    public function test_it_translates_violations_into_polish(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($owner, $organization)->request('PUT', '/api/day-close-settings', [
            'json' => ['mode' => 'day_end_time', 'dayEndsAt' => '18:00'],
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('To nie jest godzina od 00:00 do 12:00 (GG:MM).', $response->toArray(false)['violations'][0]['message'] ?? null);
    }

    public function test_it_refuses_changes_to_employees_with_a_code(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);

        foreach ([['mode' => 'employee_picks', 'dayEndsAt' => '04:30'], ['mode' => 'shift', 'dayEndsAt' => '18:00']] as $body) {
            $response = self::put($employee, $organization, $body);

            self::assertResponseStatusCodeSame(403);
            self::assertSame('cash_flow.access_forbidden.to_manage', $response->toArray(false)['code']);
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
            self::put($user, $organization, ['mode' => 'employee_picks', 'dayEndsAt' => '04:30']);
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

        self::put($ownerElsewhere, $elsewhere, ['mode' => 'employee_picks', 'dayEndsAt' => '03:00']);
        self::assertSame(self::DEFAULTS, self::get($owner, $organization)->toArray());

        self::put($owner, $organization, ['mode' => 'day_end_time', 'dayEndsAt' => '06:00']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['mode' => 'day_end_time', 'dayEndsAt' => '06:00', 'updatedAt' => '2026-11-22T10:00:00+00:00'], self::get($owner, $organization)->toArray());
        self::assertSame(['mode' => 'employee_picks', 'dayEndsAt' => '03:00', 'updatedAt' => '2026-11-22T10:00:00+00:00'], self::get($owner, $elsewhere)->toArray());

        // The employee there may read its settings but not change them.
        self::put($owner, $elsewhere, ['mode' => 'day_end_time', 'dayEndsAt' => '05:00']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('03:00', self::get($ownerElsewhere, $elsewhere)->toArray()['dayEndsAt']);
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    /** @return list<string> */
    private static function violationPaths(ResponseInterface $response): array
    {
        $found = array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath')));
        sort($found);

        return $found;
    }

    private static function get(User $user, Organization $organization): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/day-close-settings');
    }

    /** @param array<string, mixed> $body */
    private static function put(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PUT', '/api/day-close-settings', ['json' => $body]);
    }
}
