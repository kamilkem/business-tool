<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\TimeTracking\Application\Command\ApplyShiftChangeRequest\ApplyShiftChangeRequestCommand;
use App\TimeTracking\Application\ShiftPeriodLocksLoader;
use App\TimeTracking\Application\Command\ApplyShiftChangeRequest\ApplyShiftChangeRequestHandler;
use App\TimeTracking\Domain\Event\ShiftChangeRequestApplied;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestAlreadyDecidedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestDecisionForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftOverlapException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\TimeTracking\Domain\Model\ShiftSource;
use App\User\Domain\Model\User;
use App\TimeTracking\Domain\Exception\ShiftPeriodLockedException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ApplyShiftChangeRequestHandler::class)]
final class ApplyShiftChangeRequestHandlerTest extends ShiftChangeRequestTestCase
{
    public function test_it_enters_the_missing_shift_with_the_approver_as_corrector_and_publishes_it(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:30:00', 'My phone was dead');
        $newShiftId = Uuid::v7()->toRfc4122();

        $this->handle($this->manager, $request, $newShiftId);

        $shift = $this->shifts->findById(Uuid::fromString($newShiftId)) ?? self::fail('Shift not entered.');
        self::assertTrue($this->employee->getId()->equals($shift->getUserId()));
        self::assertEquals(new \DateTimeImmutable('2026-11-12 16:00:00'), $shift->getStartedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 23:30:00'), $shift->getEndedAt());
        self::assertSame('My phone was dead', $shift->getNote());
        self::assertSame(ShiftSource::Manager, $shift->getSource());
        self::assertTrue($this->manager->getId()->equals($shift->getCorrectedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $shift->getCorrectedAt());
        self::assertEquals(
            [new ShiftChangeRequestApplied($this->organizationId(), $request->getId()->toRfc4122(), $newShiftId, $this->manager->getId()->toRfc4122())],
            $this->events->events,
        );
        self::assertSame(ShiftChangeRequestStatus::Pending, $request->getStatus(), 'The request is approved by its own command.');
    }

    public function test_it_gives_the_shift_its_new_times_and_keeps_its_note(): void
    {
        $shift = Shift::clockIn(Uuid::v7(), $this->employee->getId(), new \DateTimeImmutable('2026-11-12 16:00:00'), null, 'Covering for Adam');
        $this->shifts->add($shift);
        $request = $this->newTimesRequest($this->employee, $shift, '2026-11-12 16:00:00', '2026-11-12 23:30:00');

        $this->handle($this->owner, $request);

        self::assertEquals(new \DateTimeImmutable('2026-11-12 23:30:00'), $shift->getEndedAt());
        self::assertSame('Covering for Adam', $shift->getNote());
        self::assertSame(ShiftSource::Clock, $shift->getSource());
        self::assertTrue($this->owner->getId()->equals($shift->getCorrectedBy() ?? Uuid::v7()));
        self::assertSame(1, $this->shifts->saveCallCount);
        self::assertEquals(
            [new ShiftChangeRequestApplied($this->organizationId(), $request->getId()->toRfc4122(), $shift->getId()->toRfc4122(), $this->owner->getId()->toRfc4122())],
            $this->events->events,
        );
    }

    public function test_it_lets_a_manager_apply_their_own_request(): void
    {
        $request = $this->missingShiftRequest($this->manager, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        $this->handle($this->manager, $request);

        self::assertCount(1, $this->events->events);
    }

    public function test_it_checks_overlaps_against_the_shifts_as_they_are_now_and_changes_nothing_when_they_refuse(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:30:00');
        // Since the request was submitted, a manager entered an overlapping shift.
        $this->clocked($this->employee, '2026-11-12 22:00:00', '2026-11-13 02:00:00');

        $this->expectException(ShiftOverlapException::class);
        try {
            $this->handle($this->owner, $request);
        } finally {
            self::assertCount(1, $this->shifts->all());
            self::assertSame([], $this->events->events);
            self::assertSame(ShiftChangeRequestStatus::Pending, $request->getStatus());
        }
    }

    public function test_it_refuses_to_reopen_a_shift_that_has_ended_since_the_request(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);
        $request = $this->newTimesRequest($this->employee, $shift, '2026-11-12 15:30:00', null, 'Clocked in late');
        $shift->clockOut(new \DateTimeImmutable('2026-11-12 23:00:00'));

        $this->expectException(ShiftCannotBeReopenedException::class);

        $this->handle($this->owner, $request);
    }

    public function test_it_answers_not_found_when_the_shift_no_longer_exists(): void
    {
        $shift = Shift::clockIn(Uuid::v7(), $this->employee->getId(), new \DateTimeImmutable('2026-11-12 16:00:00'), null);
        $request = $this->newTimesRequest($this->employee, $shift, '2026-11-12 16:00:00', '2026-11-12 23:00:00');

        $this->expectException(ShiftNotFoundException::class);

        $this->handle($this->owner, $request);
    }

    public function test_it_refuses_an_employee_on_their_own_request_and_hides_others_requests_from_them(): void
    {
        $own = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $others = $this->missingShiftRequest($this->otherEmployee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        try {
            $this->handle($this->employee, $own);
            self::fail('An employee applied their own request.');
        } catch (ShiftChangeRequestDecisionForbiddenException) {
        }

        try {
            $this->handle($this->employee, $others);
            self::fail('An employee saw someone else\'s request.');
        } catch (ShiftChangeRequestNotFoundException) {
        }

        self::assertSame([], $this->shifts->all());
        self::assertSame([], $this->events->events);
    }

    public function test_it_refuses_a_request_that_was_already_decided(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $request->reject($this->owner->getId(), MembershipRole::Owner, null, new \DateTimeImmutable(self::NOW));

        $this->expectException(ShiftChangeRequestAlreadyDecidedException::class);
        try {
            $this->handle($this->manager, $request);
        } finally {
            self::assertSame([], $this->shifts->all());
        }
    }

    public function test_it_answers_not_found_for_an_unknown_request(): void
    {
        $this->expectException(ShiftChangeRequestNotFoundException::class);

        $handler = new ApplyShiftChangeRequestHandler($this->requests, $this->shifts, $this->queryBus, $this->events, new ShiftPeriodLocksLoader($this->lockedPeriods, $this->queryBus), $this->clock);
        $handler(new ApplyShiftChangeRequestCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), 'not-a-uuid', Uuid::v7()->toRfc4122()));
    }

    public function test_it_refuses_a_request_pending_when_its_month_was_locked_and_changes_nothing(): void
    {
        $shift = $this->clocked($this->employee, '2026-10-30 16:00:00', '2026-10-30 23:00:00');
        $newTimes = $this->newTimesRequest($this->employee, $shift, '2026-10-30 15:00:00', '2026-10-30 23:00:00', 'Came in early');
        $missing = $this->missingShiftRequest($this->employee, '2026-10-31 19:00:00', '2026-11-01 02:00:00');
        $this->lockedPeriods->lockMonth(2026, 10);

        foreach ([$newTimes, $missing] as $request) {
            try {
                $this->handle($this->owner, $request);
                self::fail('Expected ShiftPeriodLockedException.');
            } catch (ShiftPeriodLockedException) {
                self::assertSame(ShiftChangeRequestStatus::Pending, $request->getStatus());
            }
        }

        self::assertCount(1, $this->shifts->all());
        self::assertEquals(new \DateTimeImmutable('2026-10-30 16:00:00'), $shift->getStartedAt());
        self::assertSame([], $this->events->events);
    }

    private function handle(User $actor, ShiftChangeRequest $request, ?string $newShiftId = null): void
    {
        $handler = new ApplyShiftChangeRequestHandler($this->requests, $this->shifts, $this->queryBus, $this->events, new ShiftPeriodLocksLoader($this->lockedPeriods, $this->queryBus), $this->clock);
        $handler(new ApplyShiftChangeRequestCommand(
            $this->organizationId(),
            $actor->getId()->toRfc4122(),
            $request->getId()->toRfc4122(),
            $newShiftId ?? Uuid::v7()->toRfc4122(),
        ));
    }
}
