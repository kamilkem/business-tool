<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use App\TimeTracking\Application\Query\ListWorkedShifts\ListWorkedShiftsHandler;
use App\TimeTracking\Application\Query\ListWorkedShifts\ListWorkedShiftsQuery;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftsView;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftView;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Times are written with their offset; in Europe/Warsaw a winter time is +01:00, a summer time +02:00.
 */
#[CoversClass(ListWorkedShiftsHandler::class)]
#[CoversClass(WorkedShiftView::class)]
#[CoversClass(WorkedShiftsView::class)]
final class ListWorkedShiftsHandlerTest extends TestCase
{
    private const string WARSAW = 'Europe/Warsaw';
    private const string NOW = '2026-12-15T12:00:00+01:00';

    private InMemoryShiftRepository $shifts;
    private InMemoryShiftChangeRequestRepository $changeRequests;
    private Uuid $eve;
    private Uuid $adam;

    protected function setUp(): void
    {
        $this->shifts = new InMemoryShiftRepository();
        $this->changeRequests = new InMemoryShiftChangeRequestRepository();
        $this->eve = Uuid::v7();
        $this->adam = Uuid::v7();
    }

    public function test_it_lists_the_shifts_that_started_in_the_period_oldest_first_with_their_date_and_length(): void
    {
        $this->shift($this->eve, '2026-10-31T20:00:00+01:00', '2026-11-01T03:00:00+01:00'); // October's
        $first = $this->shift($this->adam, '2026-11-02T16:00:00+01:00', '2026-11-02T23:30:59+01:00');
        $second = $this->shift($this->eve, '2026-11-30T18:00:00+01:00', '2026-11-30T22:00:00+01:00');
        $this->shift($this->eve, '2026-12-01T00:00:00+01:00', '2026-12-01T02:00:00+01:00'); // December's

        self::assertEquals(new WorkedShiftsView([
            new WorkedShiftView(
                $first,
                $this->adam->toRfc4122(),
                '2026-11-02',
                new \DateTimeImmutable('2026-11-02T15:00:00Z'),
                new \DateTimeImmutable('2026-11-02T22:30:59Z'),
                7 * 3600 + 30 * 60 + 59, // exact to the second, never rounded
            ),
            new WorkedShiftView(
                $second,
                $this->eve->toRfc4122(),
                '2026-11-30',
                new \DateTimeImmutable('2026-11-30T17:00:00Z'),
                new \DateTimeImmutable('2026-11-30T21:00:00Z'),
                4 * 3600,
            ),
        ], 0), $this->ask('2026-11-01', '2026-11-30'));
    }

    public function test_it_counts_a_night_across_the_end_of_a_month_whole_for_the_month_it_started_in(): void
    {
        $night = $this->shift($this->eve, '2026-10-31T20:00:00+01:00', '2026-11-01T03:00:00+01:00');

        $october = self::single($this->ask('2026-10-01', '2026-10-31'));
        self::assertSame($night, $october->shiftId);
        self::assertSame('2026-10-31', $october->date);
        self::assertSame(7 * 3600, $october->seconds);

        self::assertSame([], $this->ask('2026-11-01', '2026-11-30')->shifts);
    }

    public function test_it_counts_a_night_across_the_spring_change_an_hour_shorter(): void
    {
        // 29 March 2026: the clocks go from 02:00 to 03:00, so 22:00 → 06:00 is 7 hours.
        $this->shift($this->eve, '2026-03-28T22:00:00+01:00', '2026-03-29T06:00:00+02:00');

        $shift = self::single($this->ask('2026-03-01', '2026-03-31'));

        self::assertSame('2026-03-28', $shift->date);
        self::assertSame(7 * 3600, $shift->seconds);
    }

    public function test_it_counts_a_night_across_the_autumn_change_an_hour_longer(): void
    {
        // 25 October 2026: the clocks go from 03:00 back to 02:00, so 22:00 → 06:00 is 9 hours.
        $this->shift($this->eve, '2026-10-24T22:00:00+02:00', '2026-10-25T06:00:00+01:00');

        $shift = self::single($this->ask('2026-10-01', '2026-10-31'));

        self::assertSame('2026-10-24', $shift->date);
        self::assertSame(9 * 3600, $shift->seconds);
    }

    public function test_it_assigns_shifts_to_dates_in_a_zone_far_from_utc(): void
    {
        // 1 November 09:00 in Auckland (+13:00) is still 31 October in UTC.
        $morning = $this->shift($this->eve, '2026-11-01T09:00:00+13:00', '2026-11-01T17:00:00+13:00');

        $shift = self::single($this->ask('2026-11-01', '2026-11-30', 'Pacific/Auckland'));

        self::assertSame($morning, $shift->shiftId);
        self::assertSame('2026-11-01', $shift->date);
        self::assertSame([], $this->ask('2026-10-01', '2026-10-31', 'Pacific/Auckland')->shifts);
    }

    public function test_it_includes_open_shifts_without_a_length(): void
    {
        $open = $this->shift($this->eve, '2026-11-30T20:00:00+01:00', null);

        $shift = self::single($this->ask('2026-11-01', '2026-11-30'));

        self::assertSame($open, $shift->shiftId);
        self::assertNull($shift->endedAt);
        self::assertNull($shift->seconds);
    }

    public function test_it_returns_nothing_for_a_period_without_shifts_or_requests(): void
    {
        $this->shift($this->eve, '2026-10-10T16:00:00+02:00', '2026-10-10T23:00:00+02:00');
        $this->missingShiftRequest($this->eve, '2026-10-11T16:00:00+02:00', '2026-10-11T23:00:00+02:00');

        self::assertEquals(new WorkedShiftsView([], 0), $this->ask('2026-11-01', '2026-11-30'));
    }

    public function test_it_includes_the_shifts_of_members_who_were_deactivated_since(): void
    {
        // Memberships are deactivated, never deleted (ADR 0014); the query does not look at them, so
        // someone who left after working in the month is paid for it like anyone else.
        $leaver = Uuid::v7();
        $shift = $this->shift($leaver, '2026-11-03T16:00:00+01:00', '2026-11-03T22:00:00+01:00');

        $view = self::single($this->ask('2026-11-01', '2026-11-30'));

        self::assertSame($shift, $view->shiftId);
        self::assertSame($leaver->toRfc4122(), $view->userId);
    }

    public function test_it_narrows_shifts_and_requests_to_one_person(): void
    {
        $own = $this->shift($this->eve, '2026-11-03T16:00:00+01:00', '2026-11-03T22:00:00+01:00');
        $this->shift($this->adam, '2026-11-03T17:00:00+01:00', '2026-11-03T23:00:00+01:00');
        $this->missingShiftRequest($this->eve, '2026-11-04T16:00:00+01:00', '2026-11-04T22:00:00+01:00');
        $this->missingShiftRequest($this->adam, '2026-11-04T16:00:00+01:00', '2026-11-04T22:00:00+01:00');
        $this->missingShiftRequest($this->adam, '2026-11-05T16:00:00+01:00', '2026-11-05T22:00:00+01:00');

        $view = $this->ask('2026-11-01', '2026-11-30', userId: $this->eve->toRfc4122());

        self::assertSame([$own], array_map(static fn (WorkedShiftView $shift): string => $shift->shiftId, $view->shifts));
        self::assertSame(1, $view->pendingChangeRequests);
        self::assertSame(3, $this->ask('2026-11-01', '2026-11-30')->pendingChangeRequests);
    }

    public function test_it_counts_the_pending_change_requests_that_would_change_a_shift_of_the_period(): void
    {
        $november = $this->shift($this->eve, '2026-11-03T16:00:00+01:00', '2026-11-03T22:00:00+01:00');
        $october = $this->shift($this->adam, '2026-10-31T16:00:00+01:00', '2026-10-31T22:00:00+01:00');
        $alsoOctober = $this->shift($this->adam, '2026-10-30T16:00:00+01:00', '2026-10-30T22:00:00+01:00');

        // Counted: a missing shift asked to start in November, new times for a November shift (even
        // when they would move it out of November), new times moving an October shift into November.
        $this->missingShiftRequest($this->eve, '2026-11-30T20:00:00+01:00', '2026-12-01T02:00:00+01:00');
        $this->newTimesRequest($this->eve, $november, '2026-10-31T23:00:00+01:00', '2026-11-01T02:00:00+01:00');
        $this->newTimesRequest($this->adam, $october, '2026-11-01T00:00:00+01:00', '2026-11-01T02:00:00+01:00');

        // Not counted: requests about other months only, and decided ones.
        $this->missingShiftRequest($this->eve, '2026-10-31T20:00:00+01:00', '2026-11-01T02:00:00+01:00');
        $this->newTimesRequest($this->adam, $alsoOctober, '2026-10-30T17:00:00+01:00', '2026-10-30T22:00:00+01:00');
        $this->missingShiftRequest($this->eve, '2026-11-10T16:00:00+01:00', '2026-11-10T22:00:00+01:00')
            ->reject(Uuid::v7(), MembershipRole::Manager, null, new \DateTimeImmutable(self::NOW));

        self::assertSame(3, $this->ask('2026-11-01', '2026-11-30')->pendingChangeRequests);
        self::assertSame(4, $this->ask('2026-10-01', '2026-10-31')->pendingChangeRequests);
    }

    public function test_it_returns_nothing_for_a_person_id_that_is_not_a_uuid(): void
    {
        $this->shift($this->eve, '2026-11-03T16:00:00+01:00', '2026-11-03T22:00:00+01:00');

        self::assertEquals(new WorkedShiftsView([], 0), $this->ask('2026-11-01', '2026-11-30', userId: 'not-a-uuid'));
    }

    public function test_it_refuses_a_period_that_ends_before_it_starts(): void
    {
        $this->expectException(InvalidBusinessPeriodException::class);

        $this->ask('2026-11-30', '2026-11-01');
    }

    private function ask(string $from, string $to, string $timeZone = self::WARSAW, ?string $userId = null): WorkedShiftsView
    {
        return (new ListWorkedShiftsHandler($this->shifts, $this->changeRequests))(new ListWorkedShiftsQuery($from, $to, $timeZone, $userId));
    }

    private function shift(Uuid $userId, string $startedAt, ?string $endedAt): string
    {
        $shift = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable($startedAt), null);
        if (null !== $endedAt) {
            $shift->clockOut(new \DateTimeImmutable($endedAt));
        }
        $this->shifts->add($shift);

        return $shift->getId()->toRfc4122();
    }

    private function missingShiftRequest(Uuid $userId, string $startedAt, string $endedAt): ShiftChangeRequest
    {
        $request = ShiftChangeRequest::forMissingShift(Uuid::v7(), $userId, new \DateTimeImmutable($startedAt), new \DateTimeImmutable($endedAt), 'Forgot to clock in', new \DateTimeImmutable(self::NOW));
        $this->changeRequests->add($request);

        return $request;
    }

    private function newTimesRequest(Uuid $userId, string $shiftId, string $startedAt, string $endedAt): void
    {
        $shift = $this->shifts->findById(Uuid::fromString($shiftId)) ?? throw new \LogicException('No such shift.');
        $this->changeRequests->add(ShiftChangeRequest::forShift(Uuid::v7(), $userId, $shift, new \DateTimeImmutable($startedAt), new \DateTimeImmutable($endedAt), 'Wrong times', new \DateTimeImmutable(self::NOW)));
    }

    private static function single(WorkedShiftsView $view): WorkedShiftView
    {
        self::assertCount(1, $view->shifts);

        return $view->shifts[0];
    }
}
