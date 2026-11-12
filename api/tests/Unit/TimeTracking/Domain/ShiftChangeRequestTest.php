<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestAlreadyDecidedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestChangesNothingException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestDecisionForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNoteRequiredException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftChangeRequestKind;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ShiftChangeRequest::class)]
final class ShiftChangeRequestTest extends TestCase
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

    public function test_it_asks_for_a_missing_shift_in_utc_to_the_second_with_a_trimmed_note(): void
    {
        $id = Uuid::v7();

        $request = ShiftChangeRequest::forMissingShift(
            $id,
            $this->userId,
            new \DateTimeImmutable('2026-11-11T17:00:00.750+01:00'),
            new \DateTimeImmutable('2026-11-12T01:30:00+01:00'),
            "  My phone was dead \n",
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame($id, $request->getId());
        self::assertSame($this->userId, $request->getUserId());
        self::assertSame(ShiftChangeRequestKind::MissingShift, $request->getKind());
        self::assertNull($request->getShiftId());
        self::assertEquals(new \DateTimeImmutable(self::START, new \DateTimeZone('UTC')), $request->getStartedAt());
        self::assertSame('UTC', $request->getStartedAt()->getTimezone()->getName());
        self::assertSame('000000', $request->getStartedAt()->format('u'));
        self::assertEquals(new \DateTimeImmutable(self::END), $request->getEndedAt());
        self::assertSame('My phone was dead', $request->getNote());
        self::assertSame(ShiftChangeRequestStatus::Pending, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $request->getSubmittedAt());
        self::assertNull($request->getDecidedBy());
        self::assertNull($request->getDecidedAt());
        self::assertNull($request->getRejectionReason());
    }

    public function test_it_asks_for_new_times_for_one_of_the_requesters_own_shifts(): void
    {
        $shift = $this->clocked(self::START, self::END);

        $request = ShiftChangeRequest::forShift(
            Uuid::v7(),
            $this->userId,
            $shift,
            new \DateTimeImmutable('2026-11-11 15:30:00'),
            new \DateTimeImmutable(self::END),
            'I started early to set up',
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(ShiftChangeRequestKind::NewTimes, $request->getKind());
        self::assertTrue($shift->getId()->equals($request->getShiftId() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable('2026-11-11 15:30:00'), $request->getStartedAt());
    }

    public function test_it_asks_for_a_new_start_of_an_open_shift_that_stays_open(): void
    {
        $shift = $this->clocked(self::START, null);

        $request = ShiftChangeRequest::forShift(Uuid::v7(), $this->userId, $shift, new \DateTimeImmutable('2026-11-11 15:30:00'), null, 'Clocked in late', new \DateTimeImmutable(self::NOW));

        self::assertNull($request->getEndedAt());
    }

    public function test_it_asks_for_the_end_of_a_shift_the_person_forgot_to_clock_out_of(): void
    {
        $shift = $this->clocked(self::START, null);

        $request = ShiftChangeRequest::forShift(Uuid::v7(), $this->userId, $shift, new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), 'Forgot to clock out', new \DateTimeImmutable(self::NOW));

        self::assertEquals(new \DateTimeImmutable(self::END), $request->getEndedAt());
    }

    public function test_it_treats_someone_elses_shift_as_not_found(): void
    {
        $someoneElses = Shift::clockIn(Uuid::v7(), Uuid::v7(), new \DateTimeImmutable(self::START), null);

        $this->expectException(ShiftNotFoundException::class);

        ShiftChangeRequest::forShift(Uuid::v7(), $this->userId, $someoneElses, new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), 'Mine?', new \DateTimeImmutable(self::NOW));
    }

    public function test_it_refuses_to_ask_for_an_ended_shift_to_be_reopened(): void
    {
        $shift = $this->clocked(self::START, self::END);

        $this->expectException(ShiftCannotBeReopenedException::class);

        ShiftChangeRequest::forShift(Uuid::v7(), $this->userId, $shift, new \DateTimeImmutable(self::START), null, 'Still here', new \DateTimeImmutable(self::NOW));
    }

    public function test_it_refuses_new_times_that_are_the_shifts_own(): void
    {
        $shift = $this->clocked(self::START, self::END);

        $this->expectException(ShiftChangeRequestChangesNothingException::class);

        ShiftChangeRequest::forShift(Uuid::v7(), $this->userId, $shift, new \DateTimeImmutable('2026-11-11T17:00:00.400+01:00'), new \DateTimeImmutable(self::END), 'Same', new \DateTimeImmutable(self::NOW));
    }

    /** @return iterable<string, array{string, string, class-string<\Throwable>}> */
    public static function impossibleTimes(): iterable
    {
        yield 'end before start' => [self::END, self::START, ShiftEndNotAfterStartException::class];
        yield 'end at start' => [self::START, self::START, ShiftEndNotAfterStartException::class];
        yield 'end in the future' => [self::START, '2026-11-13 12:00:01', ShiftInTheFutureException::class];
        yield 'start and end in the future' => ['2026-11-14 08:00:00', '2026-11-14 16:00:00', ShiftInTheFutureException::class];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('impossibleTimes')]
    public function test_it_refuses_times_that_could_not_have_happened(string $start, string $end, string $exception): void
    {
        $this->expectException($exception);

        ShiftChangeRequest::forMissingShift(Uuid::v7(), $this->userId, new \DateTimeImmutable($start), new \DateTimeImmutable($end), 'Note', new \DateTimeImmutable(self::NOW));
    }

    public function test_it_accepts_a_shift_that_ends_right_now(): void
    {
        $request = ShiftChangeRequest::forMissingShift(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::NOW), 'Note', new \DateTimeImmutable(self::NOW));

        self::assertEquals(new \DateTimeImmutable(self::NOW), $request->getEndedAt());
    }

    public function test_it_requires_a_note(): void
    {
        $this->expectException(ShiftChangeRequestNoteRequiredException::class);

        ShiftChangeRequest::forMissingShift(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), "  \n ", new \DateTimeImmutable(self::NOW));
    }

    public function test_it_is_visible_to_owners_managers_and_the_requester_only(): void
    {
        $request = $this->missingShiftRequest();

        self::assertTrue($request->isVisibleTo($this->managerId, MembershipRole::Owner));
        self::assertTrue($request->isVisibleTo($this->managerId, MembershipRole::Manager));
        self::assertTrue($request->isVisibleTo($this->userId, MembershipRole::Employee));
        self::assertFalse($request->isVisibleTo(Uuid::v7(), MembershipRole::Employee));
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_is_approved_by_an_owner_or_manager_with_the_shift_entered_for_it(MembershipRole $role): void
    {
        $request = $this->missingShiftRequest();
        $shiftId = Uuid::v7();

        $request->approve($this->managerId, $role, $shiftId, new \DateTimeImmutable(self::NOW));

        self::assertSame(ShiftChangeRequestStatus::Approved, $request->getStatus());
        self::assertSame($shiftId, $request->getShiftId());
        self::assertSame($this->managerId, $request->getDecidedBy());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $request->getDecidedAt());
        self::assertNull($request->getRejectionReason());
    }

    public function test_it_lets_a_manager_approve_their_own_request(): void
    {
        $request = ShiftChangeRequest::forMissingShift(Uuid::v7(), $this->managerId, new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), 'Note', new \DateTimeImmutable(self::NOW));

        $request->approve($this->managerId, MembershipRole::Manager, Uuid::v7(), new \DateTimeImmutable(self::NOW));

        self::assertSame(ShiftChangeRequestStatus::Approved, $request->getStatus());
    }

    public function test_it_refuses_a_shift_other_than_the_requests_own_on_approval_as_a_programming_error(): void
    {
        $request = ShiftChangeRequest::forShift(Uuid::v7(), $this->userId, $this->clocked(self::START, null), new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), 'Note', new \DateTimeImmutable(self::NOW));

        $this->expectException(\LogicException::class);

        $request->approve($this->managerId, MembershipRole::Manager, Uuid::v7(), new \DateTimeImmutable(self::NOW));
    }

    #[DataProvider('managingRoles')]
    public function test_it_is_rejected_by_an_owner_or_manager_with_a_trimmed_reason(MembershipRole $role): void
    {
        $request = $this->missingShiftRequest();

        $request->reject($this->managerId, $role, '  You were off that day ', new \DateTimeImmutable(self::NOW));

        self::assertSame(ShiftChangeRequestStatus::Rejected, $request->getStatus());
        self::assertSame('You were off that day', $request->getRejectionReason());
        self::assertSame($this->managerId, $request->getDecidedBy());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $request->getDecidedAt());
        self::assertNull($request->getShiftId());
    }

    public function test_it_is_rejected_without_a_reason(): void
    {
        $request = $this->missingShiftRequest();

        $request->reject($this->managerId, MembershipRole::Owner, '   ', new \DateTimeImmutable(self::NOW));

        self::assertNull($request->getRejectionReason());
    }

    public function test_it_refuses_a_decision_by_an_employee_even_on_their_own_request(): void
    {
        $request = $this->missingShiftRequest();

        foreach ([
            fn () => $request->approve($this->userId, MembershipRole::Employee, Uuid::v7(), new \DateTimeImmutable(self::NOW)),
            fn () => $request->reject($this->userId, MembershipRole::Employee, null, new \DateTimeImmutable(self::NOW)),
        ] as $decide) {
            try {
                $decide();
                self::fail('An employee decided a request.');
            } catch (ShiftChangeRequestDecisionForbiddenException) {
            }
        }

        self::assertSame(ShiftChangeRequestStatus::Pending, $request->getStatus());
    }

    public function test_it_refuses_a_second_decision(): void
    {
        $request = $this->missingShiftRequest();
        $request->reject($this->managerId, MembershipRole::Manager, 'No', new \DateTimeImmutable(self::NOW));

        foreach ([
            fn () => $request->approve($this->managerId, MembershipRole::Owner, Uuid::v7(), new \DateTimeImmutable(self::NOW)),
            fn () => $request->reject($this->managerId, MembershipRole::Owner, 'Again', new \DateTimeImmutable(self::NOW)),
        ] as $decide) {
            try {
                $decide();
                self::fail('A decided request was decided again.');
            } catch (ShiftChangeRequestAlreadyDecidedException) {
            }
        }

        self::assertSame(ShiftChangeRequestStatus::Rejected, $request->getStatus());
        self::assertSame('No', $request->getRejectionReason());
    }

    private function missingShiftRequest(): ShiftChangeRequest
    {
        return ShiftChangeRequest::forMissingShift(Uuid::v7(), $this->userId, new \DateTimeImmutable(self::START), new \DateTimeImmutable(self::END), 'My phone was dead', new \DateTimeImmutable(self::NOW));
    }

    private function clocked(string $start, ?string $end): Shift
    {
        $shift = Shift::clockIn(Uuid::v7(), $this->userId, new \DateTimeImmutable($start), null);
        if (null !== $end) {
            $shift->clockOut(new \DateTimeImmutable($end));
        }

        return $shift;
    }
}
