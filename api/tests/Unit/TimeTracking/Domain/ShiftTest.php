<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\TimeTracking\Domain\Exception\ShiftAlreadyClosedException;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftOverlapException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Shift::class)]
final class ShiftTest extends TestCase
{
    private const string START = '2026-11-11 16:00:00';
    private const string END = '2026-11-12 00:30:00';
    private const string NOW = '2026-11-13 12:00:00';

    private Uuid $userId;
    private Uuid $managerId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v7();
        $this->managerId = Uuid::v7();
    }

    public function test_it_clocks_in_at_the_given_moment(): void
    {
        $id = Uuid::v7();
        $start = new \DateTimeImmutable(self::START);

        $shift = Shift::clockIn($id, $this->userId, $start, null, 'Covering for Eve');

        self::assertSame($id, $shift->getId());
        self::assertSame($this->userId, $shift->getUserId());
        self::assertSame($start, $shift->getStartedAt());
        self::assertNull($shift->getEndedAt());
        self::assertSame('Covering for Eve', $shift->getNote());
        self::assertTrue($shift->isOpen());
        self::assertSame(ShiftSource::Clock, $shift->getSource());
        self::assertNull($shift->getCorrectedBy());
        self::assertNull($shift->getCorrectedAt());
    }

    public function test_it_clocks_in_without_a_note(): void
    {
        $shift = $this->openShift();

        self::assertNull($shift->getNote());
    }

    public function test_it_refuses_to_clock_in_while_the_person_has_an_open_shift(): void
    {
        $open = $this->openShift();

        $this->expectException(ShiftAlreadyOpenException::class);

        Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::END), $open);
    }

    public function test_it_refuses_an_open_shift_of_another_person_as_a_programming_error(): void
    {
        $someoneElses = Shift::clockIn(Uuid::v7(), Uuid::v7(), new \DateTimeImmutable(self::START), null);

        $this->expectException(\LogicException::class);

        Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::END), $someoneElses);
    }

    public function test_it_refuses_a_closed_shift_as_the_open_one_as_a_programming_error(): void
    {
        $closed = $this->openShift();
        $closed->clockOut(new \DateTimeImmutable(self::END));

        $this->expectException(\LogicException::class);

        Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable('2026-11-12 16:00:00'), $closed);
    }

    public function test_it_clocks_out_at_the_given_moment(): void
    {
        $shift = $this->openShift();
        $end = new \DateTimeImmutable(self::END);

        $shift->clockOut($end);

        self::assertSame($end, $shift->getEndedAt());
        self::assertEquals(new \DateTimeImmutable(self::START), $shift->getStartedAt());
        self::assertFalse($shift->isOpen());
    }

    public function test_it_clocks_out_one_second_after_the_start(): void
    {
        $shift = $this->openShift();

        $shift->clockOut(new \DateTimeImmutable('2026-11-11 16:00:01'));

        self::assertEquals(new \DateTimeImmutable('2026-11-11 16:00:01'), $shift->getEndedAt());
    }

    public function test_it_refuses_to_end_at_the_moment_it_started(): void
    {
        $shift = $this->openShift();

        $this->expectException(ShiftEndNotAfterStartException::class);

        $shift->clockOut(new \DateTimeImmutable(self::START));
    }

    public function test_it_refuses_to_end_before_it_started(): void
    {
        $shift = $this->openShift();

        try {
            $shift->clockOut(new \DateTimeImmutable('2026-11-11 15:59:59'));
            self::fail('Expected ShiftEndNotAfterStartException.');
        } catch (ShiftEndNotAfterStartException) {
            self::assertTrue($shift->isOpen());
        }
    }

    public function test_it_refuses_to_clock_out_twice_and_keeps_the_first_end(): void
    {
        $shift = $this->openShift();
        $shift->clockOut(new \DateTimeImmutable(self::END));

        try {
            $shift->clockOut(new \DateTimeImmutable('2026-11-12 01:00:00'));
            self::fail('Expected ShiftAlreadyClosedException.');
        } catch (ShiftAlreadyClosedException) {
            self::assertEquals(new \DateTimeImmutable(self::END), $shift->getEndedAt());
        }
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_records_a_missing_shift_entered_by_an_owner_or_manager(MembershipRole $role): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);

        $shift = Shift::record($id, $this->userId, new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), '  Forgot her phone  ', $this->managerId, $role, [], $now);

        self::assertSame($id, $shift->getId());
        self::assertSame($this->userId, $shift->getUserId());
        self::assertEquals(new \DateTimeImmutable(self::START), $shift->getStartedAt());
        self::assertEquals(new \DateTimeImmutable(self::END), $shift->getEndedAt());
        self::assertSame('Forgot her phone', $shift->getNote());
        self::assertFalse($shift->isOpen());
        self::assertSame(ShiftSource::Manager, $shift->getSource());
        self::assertSame($this->managerId, $shift->getCorrectedBy());
        self::assertSame($now, $shift->getCorrectedAt());
    }

    public function test_it_stores_entered_times_in_utc_to_the_second(): void
    {
        $shift = Shift::record(
            Uuid::v7(),
            $this->userId,
            new \DateTimeImmutable('2026-11-11T17:00:00.750+01:00'),
            new \DateTimeImmutable('2026-11-12T01:30:00.250+01:00'),
            '   ',
            $this->managerId,
            MembershipRole::Manager,
            [],
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame('2026-11-11T16:00:00.000000+00:00', $shift->getStartedAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-11-12T00:30:00.000000+00:00', $shift->getEndedAt()?->format('Y-m-d\TH:i:s.uP'));
        self::assertNull($shift->getNote());
    }

    public function test_it_refuses_a_shift_entered_by_an_employee(): void
    {
        $this->expectException(ShiftChangeForbiddenException::class);

        Shift::record(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), null, $this->userId, MembershipRole::Employee, [], new \DateTimeImmutable(self::NOW));
    }

    /** @return iterable<string, array{string, string}> */
    public static function endsNotAfterTheStart(): iterable
    {
        yield 'at the start' => [self::START, self::START];
        yield 'before the start' => [self::START, '2026-11-11 15:00:00'];
        yield 'in the same second' => ['2026-11-11 16:00:00.100', '2026-11-11 16:00:00.900'];
    }

    #[DataProvider('endsNotAfterTheStart')]
    public function test_it_refuses_to_record_a_shift_that_does_not_end_after_it_starts(string $start, string $end): void
    {
        $this->expectException(ShiftEndNotAfterStartException::class);

        $this->record($start, $end);
    }

    /** @return iterable<string, array{string, string}> */
    public static function timesInTheFuture(): iterable
    {
        yield 'the end' => ['2026-11-13 08:00:00', '2026-11-13 12:00:01'];
        yield 'the whole shift' => ['2026-11-14 08:00:00', '2026-11-14 16:00:00'];
    }

    #[DataProvider('timesInTheFuture')]
    public function test_it_refuses_to_record_a_shift_in_the_future(string $start, string $end): void
    {
        $this->expectException(ShiftInTheFutureException::class);

        $this->record($start, $end);
    }

    public function test_it_records_a_shift_that_ends_now(): void
    {
        $shift = $this->record('2026-11-13 08:00:00', self::NOW);

        self::assertEquals(new \DateTimeImmutable(self::NOW), $shift->getEndedAt());
    }

    /** @return iterable<string, array{string, string}> */
    public static function overlappingTimes(): iterable
    {
        // The person worked 2026-11-11 16:00 to 2026-11-12 00:30.
        yield 'starting during it' => ['2026-11-12 00:00:00', '2026-11-12 02:00:00'];
        yield 'ending during it' => ['2026-11-11 12:00:00', '2026-11-11 16:00:01'];
        yield 'inside it' => ['2026-11-11 18:00:00', '2026-11-11 20:00:00'];
        yield 'around it' => ['2026-11-11 12:00:00', '2026-11-12 02:00:00'];
        yield 'the same times' => [self::START, self::END];
    }

    #[DataProvider('overlappingTimes')]
    public function test_it_refuses_to_record_a_shift_that_overlaps_another_one_of_the_person(string $start, string $end): void
    {
        $worked = $this->closedShift();

        $this->expectException(ShiftOverlapException::class);

        $this->record($start, $end, [$worked]);
    }

    public function test_it_records_shifts_right_before_and_right_after_another_one(): void
    {
        $worked = $this->closedShift();

        $before = $this->record('2026-11-11 08:00:00', self::START, [$worked]);
        $after = $this->record(self::END, '2026-11-12 02:00:00', [$worked, $before]);

        self::assertEquals(new \DateTimeImmutable(self::START), $before->getEndedAt());
        self::assertEquals(new \DateTimeImmutable(self::END), $after->getStartedAt());
    }

    public function test_it_treats_an_open_shift_as_running_on(): void
    {
        $open = $this->openShift(); // since 2026-11-11 16:00

        $before = $this->record('2026-11-11 08:00:00', self::START, [$open]);
        self::assertFalse($before->isOpen());

        $this->expectException(ShiftOverlapException::class);
        $this->record('2026-11-13 08:00:00', '2026-11-13 10:00:00', [$open]);
    }

    public function test_it_refuses_another_persons_shift_to_check_overlaps_as_a_programming_error(): void
    {
        $someoneElses = Shift::clockIn(Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2026-11-10 08:00:00'), null);

        $this->expectException(\LogicException::class);

        $this->record(self::START, self::END, [$someoneElses]);
    }

    #[DataProvider('managingRoles')]
    public function test_it_ends_a_shift_the_person_forgot_to_clock_out_of(MembershipRole $role): void
    {
        $shift = $this->openShift();
        $now = new \DateTimeImmutable(self::NOW);

        $shift->correct(new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), 'Forgot to clock out', $this->managerId, $role, [$shift], $now);

        self::assertEquals(new \DateTimeImmutable(self::END), $shift->getEndedAt());
        self::assertFalse($shift->isOpen());
        self::assertSame('Forgot to clock out', $shift->getNote());
        self::assertSame(ShiftSource::Clock, $shift->getSource());
        self::assertSame($this->managerId, $shift->getCorrectedBy());
        self::assertSame($now, $shift->getCorrectedAt());
    }

    public function test_it_corrects_the_start_of_an_open_shift_and_keeps_it_open(): void
    {
        $shift = $this->openShift();

        $shift->correct(new \DateTimeImmutable('2026-11-11 15:30:00'), null, null, $this->managerId, MembershipRole::Manager, [], new \DateTimeImmutable(self::NOW));

        self::assertEquals(new \DateTimeImmutable('2026-11-11 15:30:00'), $shift->getStartedAt());
        self::assertTrue($shift->isOpen());
        self::assertSame($this->managerId, $shift->getCorrectedBy());
    }

    public function test_it_records_the_last_correction(): void
    {
        $shift = $this->closedShift();
        $ownerId = Uuid::v7();

        $shift->correct(new \DateTimeImmutable(self::START), new \DateTimeImmutable('2026-11-12 01:00:00'), null, $this->managerId, MembershipRole::Manager, [], new \DateTimeImmutable('2026-11-12 10:00:00'));
        $shift->correct(new \DateTimeImmutable(self::START), new \DateTimeImmutable('2026-11-12 01:00:00'), 'Checked the till log', $ownerId, MembershipRole::Owner, [], new \DateTimeImmutable(self::NOW));

        self::assertSame('Checked the till log', $shift->getNote());
        self::assertSame($ownerId, $shift->getCorrectedBy());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $shift->getCorrectedAt());
    }

    public function test_it_changes_nothing_when_the_values_are_the_same(): void
    {
        $shift = $this->closedShift();

        $shift->correct(new \DateTimeImmutable('2026-11-11T17:00:00+01:00'), new \DateTimeImmutable(self::END), '', $this->managerId, MembershipRole::Manager, [], new \DateTimeImmutable(self::NOW));

        self::assertNull($shift->getCorrectedBy());
        self::assertNull($shift->getCorrectedAt());
    }

    public function test_it_refuses_to_reopen_an_ended_shift(): void
    {
        $shift = $this->closedShift();

        try {
            $shift->correct(new \DateTimeImmutable(self::START), null, null, $this->managerId, MembershipRole::Manager, [], new \DateTimeImmutable(self::NOW));
            self::fail('Expected ShiftCannotBeReopenedException.');
        } catch (ShiftCannotBeReopenedException) {
            self::assertEquals(new \DateTimeImmutable(self::END), $shift->getEndedAt());
            self::assertNull($shift->getCorrectedBy());
        }
    }

    public function test_it_refuses_a_correction_by_an_employee_even_of_their_own_shift(): void
    {
        $shift = $this->openShift();

        $this->expectException(ShiftChangeForbiddenException::class);

        $shift->correct(new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), null, $this->userId, MembershipRole::Employee, [], new \DateTimeImmutable(self::NOW));
    }

    public function test_it_refuses_a_correction_that_overlaps_another_shift_but_not_itself(): void
    {
        $shift = $this->closedShift();
        $next = $this->record('2026-11-12 16:00:00', '2026-11-12 23:00:00', [$shift]);

        $shift->correct(new \DateTimeImmutable('2026-11-11 15:00:00'), new \DateTimeImmutable('2026-11-12 01:00:00'), null, $this->managerId, MembershipRole::Manager, [$shift, $next], new \DateTimeImmutable(self::NOW));
        self::assertEquals(new \DateTimeImmutable('2026-11-12 01:00:00'), $shift->getEndedAt());

        try {
            $shift->correct(new \DateTimeImmutable(self::START), new \DateTimeImmutable('2026-11-12 17:00:00'), null, $this->managerId, MembershipRole::Manager, [$shift, $next], new \DateTimeImmutable(self::NOW));
            self::fail('Expected ShiftOverlapException.');
        } catch (ShiftOverlapException) {
            self::assertEquals(new \DateTimeImmutable('2026-11-11 15:00:00'), $shift->getStartedAt());
            self::assertEquals(new \DateTimeImmutable('2026-11-12 01:00:00'), $shift->getEndedAt());
        }
    }

    public function test_it_refuses_a_correction_into_the_future(): void
    {
        $shift = $this->openShift();

        $this->expectException(ShiftInTheFutureException::class);

        $shift->correct(new \DateTimeImmutable(self::START), new \DateTimeImmutable('2026-11-13 12:00:01'), null, $this->managerId, MembershipRole::Manager, [], new \DateTimeImmutable(self::NOW));
    }

    public function test_it_refuses_a_correction_that_does_not_end_after_the_start(): void
    {
        $shift = $this->closedShift();

        $this->expectException(ShiftEndNotAfterStartException::class);

        $shift->correct(new \DateTimeImmutable(self::END), new \DateTimeImmutable(self::END), null, $this->managerId, MembershipRole::Manager, [], new \DateTimeImmutable(self::NOW));
    }

    /** @param list<Shift> $personsShifts */
    private function record(string $start, string $end, array $personsShifts = []): Shift
    {
        return Shift::record(Uuid::v7(), $this->userId, new \DateTimeImmutable($start), new \DateTimeImmutable($end), null, $this->managerId, MembershipRole::Manager, $personsShifts, new \DateTimeImmutable(self::NOW));
    }

    /** 2026-11-11 16:00 to 2026-11-12 00:30, clocked by the person. */
    private function closedShift(): Shift
    {
        $shift = $this->openShift();
        $shift->clockOut(new \DateTimeImmutable(self::END));

        return $shift;
    }

    private function openShift(): Shift
    {
        return Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::START), null);
    }
}
