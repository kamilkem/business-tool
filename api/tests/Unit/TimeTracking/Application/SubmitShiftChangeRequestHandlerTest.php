<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Tests\Fixtures\OrganizationMother;
use App\TimeTracking\Application\Command\SubmitShiftChangeRequest\SubmitShiftChangeRequestCommand;
use App\TimeTracking\Application\ShiftPeriodLocksLoader;
use App\TimeTracking\Application\Command\SubmitShiftChangeRequest\SubmitShiftChangeRequestHandler;
use App\TimeTracking\Domain\Event\ShiftChangeRequestSubmitted;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Model\ShiftChangeRequestKind;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\User\Domain\Model\User;
use App\TimeTracking\Domain\Exception\ShiftPeriodLockedException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SubmitShiftChangeRequestHandler::class)]
final class SubmitShiftChangeRequestHandlerTest extends ShiftChangeRequestTestCase
{
    public function test_it_saves_a_request_for_a_missing_shift_and_publishes_that_it_was_submitted(): void
    {
        $requestId = $this->handle($this->employee, null, '2026-11-12T17:00:00+01:00', '2026-11-13T00:30:00+01:00', 'My phone was dead');

        $request = $this->requests->findById(Uuid::fromString($requestId)) ?? self::fail('Request not saved.');
        self::assertSame(ShiftChangeRequestKind::MissingShift, $request->getKind());
        self::assertSame(ShiftChangeRequestStatus::Pending, $request->getStatus());
        self::assertTrue($this->employee->getId()->equals($request->getUserId()));
        self::assertEquals(new \DateTimeImmutable('2026-11-12 16:00:00'), $request->getStartedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 23:30:00'), $request->getEndedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $request->getSubmittedAt());
        self::assertEquals(
            [new ShiftChangeRequestSubmitted($this->organizationId(), $requestId, $this->employee->getId()->toRfc4122())],
            $this->events->events,
        );
    }

    public function test_it_saves_a_request_for_new_times_for_the_requesters_own_shift(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);

        $requestId = $this->handle($this->employee, $shift->getId()->toRfc4122(), '2026-11-12T16:00:00Z', '2026-11-12T23:30:00Z', 'Forgot to clock out');

        $request = $this->requests->findById(Uuid::fromString($requestId)) ?? self::fail('Request not saved.');
        self::assertSame(ShiftChangeRequestKind::NewTimes, $request->getKind());
        self::assertTrue($shift->getId()->equals($request->getShiftId() ?? Uuid::v7()));
        self::assertTrue($shift->isOpen(), 'Submitting changes no shift.');
    }

    public function test_it_lets_a_manager_ask_for_a_change_to_their_own_shifts(): void
    {
        $this->handle($this->manager, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');

        self::assertCount(1, $this->requests->all());
    }

    public function test_it_treats_someone_elses_or_an_unknown_shift_as_not_found(): void
    {
        $someoneElses = $this->clocked($this->otherEmployee, '2026-11-12 16:00:00', null);

        foreach ([$someoneElses->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $shiftId) {
            try {
                $this->handle($this->employee, $shiftId, '2026-11-12T16:00:00Z', '2026-11-12T23:00:00Z');
                self::fail(\sprintf('Shift "%s" was found.', $shiftId));
            } catch (ShiftNotFoundException) {
            }
        }

        // A manager asks for their own shifts only, too.
        $this->expectException(ShiftNotFoundException::class);
        try {
            $this->handle($this->manager, $someoneElses->getId()->toRfc4122(), '2026-11-12T16:00:00Z', '2026-11-12T23:00:00Z');
        } finally {
            self::assertSame([], $this->requests->all());
            self::assertSame([], $this->events->events);
        }
    }

    public function test_it_refuses_a_deactivated_member(): void
    {
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-13 09:00:00'));

        $this->expectException(OrganizationNotFoundException::class);

        $this->handle($this->employee, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
    }

    public function test_it_refuses_a_missing_shift_without_its_end_as_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->handle($this->employee, null, '2026-11-12T08:00:00Z', null);
    }

    public function test_it_refuses_requests_about_a_locked_month_and_saves_and_publishes_nothing(): void
    {
        $october = $this->clocked($this->employee, '2026-10-30 16:00:00', '2026-10-30 23:00:00');
        $november = $this->clocked($this->employee, '2026-11-02 16:00:00', '2026-11-02 23:00:00');
        $this->lockedPeriods->lockMonth(2026, 10);

        $refused = [
            'missing shift' => [null, '2026-10-31T20:00:00+01:00', '2026-11-01T03:00:00+01:00'],
            'new times for a locked shift' => [$october->getId()->toRfc4122(), '2026-10-30T15:00:00Z', '2026-10-30T23:00:00Z'],
            'new times into the locked month' => [$november->getId()->toRfc4122(), '2026-10-31T16:00:00Z', '2026-10-31T23:00:00Z'],
        ];
        foreach ($refused as $case => [$shiftId, $startedAt, $endedAt]) {
            try {
                $this->handle($this->employee, $shiftId, $startedAt, $endedAt);
                self::fail(\sprintf('The %s was accepted.', $case));
            } catch (ShiftPeriodLockedException) {
            }
        }
        self::assertSame([], $this->requests->all());
        self::assertSame([], $this->events->events);

        $this->handle($this->employee, $november->getId()->toRfc4122(), '2026-11-02T15:00:00Z', '2026-11-02T23:00:00Z');
        self::assertCount(1, $this->requests->all());
    }

    private function handle(User $actor, ?string $shiftId, string $startedAt, ?string $endedAt, string $note = 'Please fix'): string
    {
        $requestId = Uuid::v7()->toRfc4122();
        $handler = new SubmitShiftChangeRequestHandler($this->requests, $this->shifts, $this->queryBus, $this->events, new ShiftPeriodLocksLoader($this->lockedPeriods, $this->queryBus), $this->clock);
        $handler(new SubmitShiftChangeRequestCommand($this->organizationId(), $actor->getId()->toRfc4122(), $requestId, $shiftId, $startedAt, $endedAt, $note));

        return $requestId;
    }
}
