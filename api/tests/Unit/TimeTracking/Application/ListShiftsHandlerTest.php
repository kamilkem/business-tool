<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\ListMembers\ListMembersHandler;
use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\TimeTracking\Application\Query\ListShifts\ListShiftsHandler;
use App\TimeTracking\Application\Query\ListShifts\ListShiftsQuery;
use App\TimeTracking\Application\Query\ListShifts\ShiftListItemView;
use App\TimeTracking\Domain\Exception\ShiftPeriodTooLongException;
use App\TimeTracking\Domain\Model\Shift;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The Organization module answers through its real query handlers over an in-memory repository, so
 * who sees whose shifts is its own rule, as in production.
 */
#[CoversClass(ListShiftsHandler::class)]
#[CoversClass(ShiftListItemView::class)]
final class ListShiftsHandlerTest extends TestCase
{
    private const string JOINED = '2026-01-05 09:00:00';

    private InMemoryShiftRepository $shifts;
    private InMemoryOrganizationRepository $organizations;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $colleague;

    protected function setUp(): void
    {
        $this->shifts = new InMemoryShiftRepository();
        $this->organizations = new InMemoryOrganizationRepository();
        $this->owner = self::user('olivia@example.com', 'Olivia', 'Nowak');
        $this->manager = self::user('mark@example.com', 'Mark', 'Kowalski');
        $this->employee = self::user('eve@example.com', 'Eve', 'Wiśniewska');
        $this->colleague = self::user('adam@example.com', 'Adam', 'Zieliński');
        $this->organization = new Organization(Uuid::v7(), 'Corner Pub', $this->owner, new \DateTimeImmutable(self::JOINED), timezone: 'Europe/Warsaw');
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, new \DateTimeImmutable(self::JOINED));
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, new \DateTimeImmutable(self::JOINED));
        OrganizationMother::addMember($this->organization, $this->colleague, MembershipRole::Employee, new \DateTimeImmutable(self::JOINED));
        $this->organizations->add($this->organization);
    }

    public function test_it_lists_everyones_shifts_newest_first_for_owners_and_managers(): void
    {
        $first = $this->shift($this->employee, '2026-11-10 15:00:00', '2026-11-10 23:00:00');
        $second = $this->shift($this->colleague, '2026-11-11 15:00:00', '2026-11-11 22:00:00');
        $third = $this->shift($this->manager, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $this->shift($this->employee, '2026-11-13 15:00:00', '2026-11-13 23:00:00'); // after the period

        foreach ([$this->owner, $this->manager] as $actor) {
            self::assertSame([$third, $second, $first], self::ids($this->ask($actor, '2026-11-10', '2026-11-12')));
        }
    }

    public function test_it_describes_each_shift_with_the_persons_name_its_date_and_its_duration(): void
    {
        $shift = $this->shift($this->employee, '2026-11-11 15:00:00', '2026-11-11 23:30:59', 'Covered for Adam');

        self::assertEquals([new ShiftListItemView(
            $shift,
            $this->employee->getId()->toRfc4122(),
            'Eve',
            'Wiśniewska',
            '2026-11-11',
            new \DateTimeImmutable('2026-11-11 15:00:00'),
            new \DateTimeImmutable('2026-11-11 23:30:59'),
            510, // whole minutes: 8 h 30 min 59 s
            'Covered for Adam',
            'clock',
            null,
            null,
        )], $this->ask($this->owner, '2026-11-11', '2026-11-11'));
    }

    public function test_it_includes_open_shifts_without_a_duration(): void
    {
        $open = $this->shift($this->employee, '2026-11-11 15:00:00', null);

        $view = self::single($this->ask($this->manager, '2026-11-11', '2026-11-11'));

        self::assertSame($open, $view->id);
        self::assertNull($view->endedAt);
        self::assertNull($view->durationMinutes);
    }

    public function test_it_lists_only_an_employees_own_shifts(): void
    {
        $own = $this->shift($this->employee, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        $this->shift($this->colleague, '2026-11-11 16:00:00', '2026-11-11 23:00:00');
        $this->shift($this->owner, '2026-11-11 17:00:00', null);

        self::assertSame([$own], self::ids($this->ask($this->employee, '2026-11-11', '2026-11-11')));
        self::assertSame([$own], self::ids($this->ask($this->employee, '2026-11-11', '2026-11-11', $this->employee)));
    }

    public function test_it_refuses_an_employee_who_asks_for_someone_elses_shifts(): void
    {
        $this->shift($this->colleague, '2026-11-11 16:00:00', '2026-11-11 23:00:00');

        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->ask($this->employee, '2026-11-11', '2026-11-11', $this->colleague);
    }

    public function test_it_narrows_the_shifts_to_one_person_for_a_manager(): void
    {
        $this->shift($this->employee, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        $colleagues = $this->shift($this->colleague, '2026-11-11 16:00:00', '2026-11-11 23:00:00');

        self::assertSame([$colleagues], self::ids($this->ask($this->manager, '2026-11-11', '2026-11-11', $this->colleague)));
    }

    public function test_it_refuses_to_narrow_the_shifts_to_someone_who_is_not_a_member(): void
    {
        $this->expectException(MemberNotFoundException::class);

        $this->ask($this->owner, '2026-11-11', '2026-11-11', self::user('stranger@example.com', 'Sam', 'Stranger'));
    }

    public function test_it_keeps_the_shifts_of_deactivated_members_visible_to_managers(): void
    {
        $shift = $this->shift($this->employee, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-12 09:00:00'));

        self::assertSame([$shift], self::ids($this->ask($this->manager, '2026-11-11', '2026-11-11')));
        self::assertSame([$shift], self::ids($this->ask($this->manager, '2026-11-11', '2026-11-11', $this->employee)));
        self::assertSame('Eve', self::single($this->ask($this->manager, '2026-11-11', '2026-11-11'))->firstName);
    }

    public function test_it_puts_a_shift_on_the_business_date_it_started_on_even_when_it_ends_after_midnight(): void
    {
        // 23:30 to 03:00 in Warsaw (UTC+1 in November).
        $shift = $this->shift($this->employee, '2026-11-11 22:30:00', '2026-11-12 02:00:00');

        self::assertSame([$shift], self::ids($this->ask($this->owner, '2026-11-11', '2026-11-11')));
        self::assertSame('2026-11-11', self::single($this->ask($this->owner, '2026-11-11', '2026-11-11'))->date);
        self::assertSame([], $this->ask($this->owner, '2026-11-12', '2026-11-12'));
    }

    public function test_it_draws_the_line_between_dates_at_local_midnight_when_the_clocks_go_forward(): void
    {
        // 29 March 2026, the last Sunday of March: Warsaw moves from UTC+1 to UTC+2 at 02:00, so the
        // day lasts 23 hours, from 2026-03-28 23:00 UTC to 2026-03-29 22:00 UTC.
        $saturdayLate = $this->shift($this->employee, '2026-03-28 22:59:59', '2026-03-29 02:00:00'); // 23:59:59 on Saturday
        $sundayEarly = $this->shift($this->colleague, '2026-03-28 23:00:00', '2026-03-29 03:00:00'); // 00:00 on Sunday
        $sundayLate = $this->shift($this->manager, '2026-03-29 21:30:00', '2026-03-30 01:00:00'); // 23:30 on Sunday
        $mondayEarly = $this->shift($this->owner, '2026-03-29 22:00:00', '2026-03-30 02:00:00'); // 00:00 on Monday

        self::assertSame([$sundayLate, $sundayEarly], self::ids($this->ask($this->owner, '2026-03-29', '2026-03-29')));
        self::assertSame([$saturdayLate], self::ids($this->ask($this->owner, '2026-03-28', '2026-03-28')));
        self::assertSame([$mondayEarly], self::ids($this->ask($this->owner, '2026-03-30', '2026-03-30')));
        self::assertSame(['2026-03-29', '2026-03-29'], array_map(static fn (ShiftListItemView $view): string => $view->date, $this->ask($this->owner, '2026-03-29', '2026-03-29') ?? []));
    }

    public function test_it_uses_the_organizations_time_zone(): void
    {
        $this->organization->changeSettings($this->owner->getId(), 'Corner Pub', null, 'NZ', 'NZD', 'Pacific/Auckland');
        // 10:30 on 12 November in Auckland (UTC+13).
        $shift = $this->shift($this->employee, '2026-11-11 21:30:00', '2026-11-12 05:00:00');

        self::assertSame([$shift], self::ids($this->ask($this->owner, '2026-11-12', '2026-11-12')));
        self::assertSame('2026-11-12', self::single($this->ask($this->owner, '2026-11-12', '2026-11-12'))->date);
        self::assertSame([], $this->ask($this->owner, '2026-11-11', '2026-11-11'));
    }

    public function test_it_accepts_a_period_of_the_longest_allowed_length(): void
    {
        $first = $this->shift($this->employee, '2026-08-11 10:00:00', '2026-08-11 18:00:00');
        $last = $this->shift($this->employee, '2026-11-10 10:00:00', '2026-11-10 18:00:00');

        self::assertSame([$last, $first], self::ids($this->ask($this->owner, '2026-08-11', '2026-11-10'))); // 92 days
    }

    public function test_it_refuses_a_period_longer_than_the_limit(): void
    {
        $this->expectException(ShiftPeriodTooLongException::class);

        $this->ask($this->owner, '2026-08-10', '2026-11-10'); // 93 days
    }

    public function test_it_refuses_a_period_that_ends_before_it_starts(): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);

        $this->ask($this->owner, '2026-11-12', '2026-11-11');
    }

    public function test_it_refuses_a_date_that_does_not_exist(): void
    {
        $this->expectException(InvalidBusinessDateException::class);

        $this->ask($this->owner, '2026-02-29', '2026-03-01');
    }

    public function test_it_returns_null_for_someone_who_is_not_an_active_member(): void
    {
        $this->shift($this->employee, '2026-11-11 15:00:00', '2026-11-11 23:00:00');
        OrganizationMother::deactivateMember($this->organization, $this->colleague, new \DateTimeImmutable('2026-11-10 09:00:00'));

        self::assertNull($this->ask(self::user('stranger@example.com', 'Sam', 'Stranger'), '2026-11-11', '2026-11-11'));
        self::assertNull($this->ask($this->colleague, '2026-11-11', '2026-11-11'));
        self::assertNull($this->handle(new ListShiftsQuery(Uuid::v7()->toRfc4122(), $this->owner->getId()->toRfc4122(), '2026-11-11', '2026-11-11')));
        self::assertNull($this->handle(new ListShiftsQuery($this->organization->getId()->toRfc4122(), 'not-a-uuid', '2026-11-11', '2026-11-11')));
    }

    /** @return list<ShiftListItemView>|null */
    private function ask(User $actor, string $from, string $to, ?User $only = null): ?array
    {
        return $this->handle(new ListShiftsQuery(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $from,
            $to,
            $only?->getId()->toRfc4122(),
        ));
    }

    /** @return list<ShiftListItemView>|null */
    private function handle(ListShiftsQuery $query): ?array
    {
        $organizations = $this->organizations;
        $queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof ListMembersQuery => (new ListMembersHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });

        return (new ListShiftsHandler($this->shifts, $queryBus))($query);
    }

    /** @return string the shift's id */
    private function shift(User $user, string $startedAt, ?string $endedAt, ?string $note = null): string
    {
        $shift = Shift::clockIn(Uuid::v7(), $user->getId(), new \DateTimeImmutable($startedAt, new \DateTimeZone('UTC')), null, $note);
        if (null !== $endedAt) {
            $shift->clockOut(new \DateTimeImmutable($endedAt, new \DateTimeZone('UTC')));
        }
        $this->shifts->add($shift);

        return $shift->getId()->toRfc4122();
    }

    /**
     * @param list<ShiftListItemView>|null $views
     *
     * @return list<string>
     */
    private static function ids(?array $views): array
    {
        return array_map(static fn (ShiftListItemView $view): string => $view->id, $views ?? []);
    }

    /** @param list<ShiftListItemView>|null $views */
    private static function single(?array $views): ShiftListItemView
    {
        self::assertNotNull($views);
        self::assertCount(1, $views);

        return $views[0];
    }

    private static function user(string $email, string $firstName, string $lastName): User
    {
        return new User(Uuid::v7(), $email, new \DateTimeImmutable(self::JOINED), $firstName, $lastName);
    }
}
