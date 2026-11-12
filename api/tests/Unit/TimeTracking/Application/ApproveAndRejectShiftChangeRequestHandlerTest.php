<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Tests\Fixtures\OrganizationMother;
use App\TimeTracking\Application\Command\ApproveShiftChangeRequest\ApproveShiftChangeRequestCommand;
use App\TimeTracking\Application\Command\ApproveShiftChangeRequest\ApproveShiftChangeRequestHandler;
use App\TimeTracking\Application\Command\RejectShiftChangeRequest\RejectShiftChangeRequestCommand;
use App\TimeTracking\Application\Command\RejectShiftChangeRequest\RejectShiftChangeRequestHandler;
use App\TimeTracking\Domain\Event\ShiftChangeRequestDecided;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestAlreadyDecidedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestDecisionForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ApproveShiftChangeRequestHandler::class)]
#[CoversClass(RejectShiftChangeRequestHandler::class)]
final class ApproveAndRejectShiftChangeRequestHandlerTest extends ShiftChangeRequestTestCase
{
    public function test_it_saves_the_approval_and_publishes_the_decision(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $shiftId = Uuid::v7();

        $this->approve($this->manager, $request, $shiftId->toRfc4122());

        self::assertSame(ShiftChangeRequestStatus::Approved, $request->getStatus());
        self::assertTrue($shiftId->equals($request->getShiftId() ?? Uuid::v7()));
        self::assertTrue($this->manager->getId()->equals($request->getDecidedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $request->getDecidedAt());
        self::assertSame(1, $this->requests->saveCallCount);
        self::assertEquals([$this->decided($request, $this->manager, 'approved')], $this->events->events);
    }

    public function test_it_saves_the_rejection_with_its_reason_and_publishes_the_decision(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        $this->reject($this->owner, $request, 'You were off that day');

        self::assertSame(ShiftChangeRequestStatus::Rejected, $request->getStatus());
        self::assertSame('You were off that day', $request->getRejectionReason());
        self::assertSame(1, $this->requests->saveCallCount);
        self::assertEquals([$this->decided($request, $this->owner, 'rejected')], $this->events->events);
    }

    public function test_it_lets_a_manager_decide_their_own_request(): void
    {
        $approved = $this->missingShiftRequest($this->manager, '2026-11-11 08:00:00', '2026-11-11 16:00:00');
        $rejected = $this->missingShiftRequest($this->manager, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        $this->approve($this->manager, $approved, Uuid::v7()->toRfc4122());
        $this->reject($this->manager, $rejected, null);

        self::assertSame([ShiftChangeRequestStatus::Approved, ShiftChangeRequestStatus::Rejected], [$approved->getStatus(), $rejected->getStatus()]);
    }

    public function test_it_refuses_an_employee_and_hides_others_requests_from_them(): void
    {
        $own = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $others = $this->missingShiftRequest($this->otherEmployee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        foreach ([[$own, ShiftChangeRequestDecisionForbiddenException::class], [$others, ShiftChangeRequestNotFoundException::class]] as [$request, $expected]) {
            foreach ([fn () => $this->approve($this->employee, $request, Uuid::v7()->toRfc4122()), fn () => $this->reject($this->employee, $request, null)] as $decide) {
                try {
                    $decide();
                    self::fail('An employee decided a request.');
                } catch (\Throwable $e) {
                    self::assertInstanceOf($expected, $e);
                }
            }
        }

        self::assertSame([ShiftChangeRequestStatus::Pending, ShiftChangeRequestStatus::Pending], [$own->getStatus(), $others->getStatus()]);
        self::assertSame([], $this->events->events);
    }

    public function test_it_refuses_a_second_decision_without_publishing_one(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $this->reject($this->owner, $request, 'No');
        $this->events->events = [];

        foreach ([fn () => $this->approve($this->manager, $request, Uuid::v7()->toRfc4122()), fn () => $this->reject($this->manager, $request, 'Again')] as $decide) {
            try {
                $decide();
                self::fail('A decided request was decided again.');
            } catch (ShiftChangeRequestAlreadyDecidedException) {
            }
        }

        self::assertSame([], $this->events->events);
        self::assertSame('No', $request->getRejectionReason());
    }

    public function test_it_refuses_a_deactivated_manager(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        OrganizationMother::deactivateMember($this->organization, $this->manager, new \DateTimeImmutable('2026-11-13 09:00:00'));

        $this->expectException(OrganizationNotFoundException::class);

        $this->reject($this->manager, $request, null);
    }

    public function test_it_answers_not_found_for_an_unknown_request(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $requestId) {
            foreach ([
                fn () => (new ApproveShiftChangeRequestHandler($this->requests, $this->queryBus, $this->events, $this->clock))(new ApproveShiftChangeRequestCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), $requestId, Uuid::v7()->toRfc4122())),
                fn () => (new RejectShiftChangeRequestHandler($this->requests, $this->queryBus, $this->events, $this->clock))(new RejectShiftChangeRequestCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), $requestId, null)),
            ] as $decide) {
                try {
                    $decide();
                    self::fail(\sprintf('Request "%s" was found.', $requestId));
                } catch (ShiftChangeRequestNotFoundException) {
                }
            }
        }

        self::assertSame([], $this->events->events);
    }

    private function approve(User $actor, ShiftChangeRequest $request, string $shiftId): void
    {
        $handler = new ApproveShiftChangeRequestHandler($this->requests, $this->queryBus, $this->events, $this->clock);
        $handler(new ApproveShiftChangeRequestCommand($this->organizationId(), $actor->getId()->toRfc4122(), $request->getId()->toRfc4122(), $shiftId));
    }

    private function reject(User $actor, ShiftChangeRequest $request, ?string $reason): void
    {
        $handler = new RejectShiftChangeRequestHandler($this->requests, $this->queryBus, $this->events, $this->clock);
        $handler(new RejectShiftChangeRequestCommand($this->organizationId(), $actor->getId()->toRfc4122(), $request->getId()->toRfc4122(), $reason));
    }

    private function decided(ShiftChangeRequest $request, User $decider, string $status): ShiftChangeRequestDecided
    {
        return new ShiftChangeRequestDecided(
            $this->organizationId(),
            $request->getId()->toRfc4122(),
            $request->getUserId()->toRfc4122(),
            $decider->getId()->toRfc4122(),
            $status,
        );
    }
}
