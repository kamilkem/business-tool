<?php

declare(strict_types=1);

namespace App\Tests\Api\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /shifts?from=&to=[&userId=]: shifts by the business date they started on, in the organization's
 * time zone (ADR 0017). Shifts are recorded through the clock-in and clock-out endpoints at mocked
 * server times (UTC); the organizations are in Europe/Warsaw unless a test says otherwise.
 */
final class ShiftListApiTest extends ApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lists_everyones_shifts_newest_first_for_owners_and_managers(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee, 'Eve', 'Wiśniewska');
        $colleague = self::addMember($organization, MembershipRole::Employee, 'Adam', 'Zieliński');
        $closed = self::workShift($employee, $organization, '2026-11-10 15:00:00', '2026-11-10 23:30:59');
        $own = self::workShift($actor, $organization, '2026-11-11 08:00:00', '2026-11-11 16:00:00');
        $open = self::workShift($colleague, $organization, '2026-11-12 15:00:00', null);
        self::workShift($employee, $organization, '2026-11-09 15:00:00', '2026-11-09 23:00:00'); // before the period

        $response = self::list($actor, $organization, '2026-11-10', '2026-11-12');

        self::assertResponseStatusCodeSame(200);
        $shifts = $response->toArray();
        self::assertSame([$open, $own, $closed], array_column($shifts, 'id'));
        self::assertSame([
            'id' => $closed,
            'userId' => $employee->getId()->toRfc4122(),
            'firstName' => 'Eve',
            'lastName' => 'Wiśniewska',
            'date' => '2026-11-10',
            'startedAt' => '2026-11-10T15:00:00+00:00',
            'endedAt' => '2026-11-10T23:30:59+00:00',
            'durationMinutes' => 510,
            'note' => null,
        ], $shifts[2]);
        self::assertSame(['Adam', 'Zieliński', '2026-11-12', null, null], [
            $shifts[0]['firstName'], $shifts[0]['lastName'], $shifts[0]['date'], $shifts[0]['endedAt'], $shifts[0]['durationMinutes'],
        ]);
    }

    public function test_it_lists_only_an_employees_own_shifts(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $colleague = self::addMember($organization, MembershipRole::Employee);
        $own = self::workShift($employee, $organization, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        self::workShift($colleague, $organization, '2026-11-11 16:00:00', '2026-11-11 23:00:00');
        self::workShift($owner, $organization, '2026-11-11 17:00:00', null);

        $all = self::list($employee, $organization, '2026-11-11', '2026-11-11');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$own], array_column($all->toArray(), 'id'));

        $mine = self::list($employee, $organization, '2026-11-11', '2026-11-11', $employee->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$own], array_column($mine->toArray(), 'id'));
    }

    public function test_it_refuses_an_employee_who_asks_for_someone_elses_shifts(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $colleague = self::addMember($organization, MembershipRole::Employee);
        self::workShift($colleague, $organization, '2026-11-11 16:00:00', '2026-11-11 23:00:00');

        foreach ([$colleague, $owner] as $other) {
            self::list($employee, $organization, '2026-11-11', '2026-11-11', $other->getId()->toRfc4122());
            self::assertResponseStatusCodeSame(403);
        }
    }

    #[DataProvider('managingRoles')]
    public function test_it_narrows_the_shifts_to_one_person(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $colleague = self::addMember($organization, MembershipRole::Employee);
        $employees = self::workShift($employee, $organization, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        self::workShift($colleague, $organization, '2026-11-11 16:00:00', '2026-11-11 23:00:00');
        self::workShift($actor, $organization, '2026-11-11 17:00:00', '2026-11-11 23:00:00');

        $response = self::list($actor, $organization, '2026-11-11', '2026-11-11', $employee->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$employees], array_column($response->toArray(), 'id'));
    }

    public function test_it_answers_404_when_narrowing_to_someone_who_is_not_a_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$memberElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Employee);
        self::workShift($memberElsewhere, $elsewhere, '2026-11-11 15:00:00', '2026-11-11 23:00:00');

        foreach ([$memberElsewhere->getId()->toRfc4122(), Uuid::v7()->toRfc4122()] as $userId) {
            self::list($owner, $organization, '2026-11-11', '2026-11-11', $userId);
            self::assertResponseStatusCodeSame(404, $userId);
        }
    }

    public function test_it_keeps_the_shifts_of_deactivated_members_visible_to_managers(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee, 'Eve', 'Wiśniewska');
        $shift = self::workShift($employee, $organization, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$employee->getId()->toRfc4122().'/deactivate');
        self::assertResponseStatusCodeSame(204);

        $all = self::list($manager, $organization, '2026-11-11', '2026-11-11');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([[$shift, 'Eve', 'Wiśniewska']], array_map(
            static fn (array $item): array => [$item['id'], $item['firstName'], $item['lastName']],
            $all->toArray(),
        ));

        $only = self::list($manager, $organization, '2026-11-11', '2026-11-11', $employee->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$shift], array_column($only->toArray(), 'id'));

        self::list($employee, $organization, '2026-11-11', '2026-11-11');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_puts_a_shift_on_the_business_date_it_started_on_when_the_clocks_go_forward(): void
    {
        // 29 March 2026, the last Sunday of March: Warsaw moves from UTC+1 to UTC+2, so 23:30 local
        // is 21:30 UTC, and that Sunday runs from 2026-03-28 23:00 UTC to 2026-03-29 22:00 UTC.
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $saturdayNight = self::workShift($employee, $organization, '2026-03-28 22:30:00', '2026-03-29 01:30:00'); // 23:30 on Saturday (UTC+1)
        $sundayNight = self::workShift($employee, $organization, '2026-03-29 21:30:00', '2026-03-30 01:00:00'); // 23:30 on Sunday (UTC+2)
        $mondayMorning = self::workShift($owner, $organization, '2026-03-29 22:00:00', '2026-03-30 02:00:00'); // 00:00 on Monday

        $sunday = self::list($owner, $organization, '2026-03-29', '2026-03-29')->toArray();
        self::assertSame([$sundayNight], array_column($sunday, 'id'));
        self::assertSame('2026-03-29', $sunday[0]['date']);
        self::assertSame(210, $sunday[0]['durationMinutes']);

        self::assertSame([$saturdayNight], array_column(self::list($owner, $organization, '2026-03-28', '2026-03-28')->toArray(), 'id'));
        self::assertSame([$mondayMorning], array_column(self::list($owner, $organization, '2026-03-30', '2026-03-30')->toArray(), 'id'));
        self::assertSame(
            [$mondayMorning, $sundayNight, $saturdayNight],
            array_column(self::list($owner, $organization, '2026-03-28', '2026-03-30')->toArray(), 'id'),
        );
    }

    public function test_it_uses_the_organizations_time_zone(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $organization->changeSettings($owner->getId(), $organization->getName(), null, 'US', 'USD', 'America/New_York');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        // 20:00 on 11 November in New York (UTC-5) is already the 12th in UTC.
        $shift = self::workShift($owner, $organization, '2026-11-12 01:00:00', '2026-11-12 05:00:00');

        $eleventh = self::list($owner, $organization, '2026-11-11', '2026-11-11')->toArray();
        self::assertSame([$shift], array_column($eleventh, 'id'));
        self::assertSame('2026-11-11', $eleventh[0]['date']);
        self::assertSame([], self::list($owner, $organization, '2026-11-12', '2026-11-12')->toArray());
    }

    public function test_it_accepts_a_period_of_92_days(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $first = self::workShift($owner, $organization, '2026-08-11 10:00:00', '2026-08-11 18:00:00');
        $last = self::workShift($owner, $organization, '2026-11-10 10:00:00', '2026-11-10 18:00:00');

        $response = self::list($owner, $organization, '2026-08-11', '2026-11-10');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$last, $first], array_column($response->toArray(), 'id'));
    }

    /** @return iterable<string, array{array<string, string|list<string>>, string}> */
    public static function invalidQueries(): iterable
    {
        yield 'no from' => [['to' => '2026-11-11'], 'from'];
        yield 'no to' => [['from' => '2026-11-11'], 'to'];
        yield 'empty from' => [['from' => '', 'to' => '2026-11-11'], 'from'];
        yield 'from not YYYY-MM-DD' => [['from' => '11.11.2026', 'to' => '2026-11-11'], 'from'];
        yield 'from without leading zeros' => [['from' => '2026-1-1', 'to' => '2026-11-11'], 'from'];
        yield 'from with a time' => [['from' => '2026-11-11T00:00:00', 'to' => '2026-11-11'], 'from'];
        yield 'to that does not exist' => [['from' => '2026-02-01', 'to' => '2026-02-29'], 'to'];
        yield 'from as an array' => [['from' => ['2026-11-11'], 'to' => '2026-11-11'], 'from'];
        yield 'to before from' => [['from' => '2026-11-12', 'to' => '2026-11-11'], 'to'];
        yield '93 days' => [['from' => '2026-08-10', 'to' => '2026-11-10'], 'to'];
        yield 'userId not a UUID' => [['from' => '2026-11-11', 'to' => '2026-11-11', 'userId' => 'eve'], 'userId'];
    }

    /** @param array<string, string|list<string>> $query */
    #[DataProvider('invalidQueries')]
    public function test_it_answers_422_for_invalid_parameters(array $query, string $invalidParameter): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($owner, $organization)->request('GET', '/api/shifts', ['query' => $query]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$invalidParameter], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
    }

    public function test_it_keeps_another_organizations_shifts_invisible(): void
    {
        [$owner, $here] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $hereShift = self::workShift($owner, $here, '2026-11-11 08:00:00', '2026-11-11 12:00:00');
        $theirShift = self::workShift($owner, $elsewhere, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        $otherShift = self::workShift($ownerElsewhere, $elsewhere, '2026-11-11 16:00:00', '2026-11-11 23:00:00');

        self::assertSame([$hereShift], array_column(self::list($owner, $here, '2026-11-11', '2026-11-11')->toArray(), 'id'));
        self::assertSame([$hereShift], array_column(self::list($owner, $here, '2026-11-11', '2026-11-11', $owner->getId()->toRfc4122())->toArray(), 'id'));
        self::assertSame(
            [$otherShift, $theirShift],
            array_column(self::list($ownerElsewhere, $elsewhere, '2026-11-11', '2026-11-11')->toArray(), 'id'),
        );

        self::list($owner, $here, '2026-11-11', '2026-11-11', $ownerElsewhere->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_refuses_a_person_who_is_not_an_active_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $deactivated = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $deactivated);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([UserFactory::createOne(), $deactivated] as $user) {
            self::list($user, $organization, '2026-11-11', '2026-11-11');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function test_it_requires_an_organization_and_authentication(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::clientFor($owner)->request('GET', '/api/shifts', ['query' => ['from' => '2026-11-11', 'to' => '2026-11-11']]);
        self::assertResponseStatusCodeSame(400);

        static::createClient()->request('GET', '/api/shifts', [
            'query' => ['from' => '2026-11-11', 'to' => '2026-11-11'],
            'headers' => ['Accept' => 'application/json'],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    private static function list(User $user, Organization $organization, string $from, string $to, ?string $userId = null): ResponseInterface
    {
        $query = ['from' => $from, 'to' => $to] + (null === $userId ? [] : ['userId' => $userId]);

        return static::clientFor($user, $organization)->request('GET', '/api/shifts', ['query' => $query]);
    }

    /** Clocks the person in and, unless $endedAt is null, out, at those server times (UTC); returns the shift's id. */
    private static function workShift(User $user, Organization $organization, string $startedAt, ?string $endedAt): string
    {
        self::mockTime($startedAt);
        $shift = static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-in')->toArray();

        if (null !== $endedAt) {
            self::mockTime($endedAt);
            static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-out');
            self::assertResponseStatusCodeSame(200);
        }

        return $shift['id'];
    }

    private static function addMember(Organization $organization, MembershipRole $role, ?string $firstName = null, ?string $lastName = null): User
    {
        $user = UserFactory::createOne(array_filter(['firstName' => $firstName, 'lastName' => $lastName]));
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }
}
