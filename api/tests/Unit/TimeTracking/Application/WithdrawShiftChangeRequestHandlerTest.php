<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\TimeTracking\Application\Command\WithdrawShiftChangeRequest\WithdrawShiftChangeRequestCommand;
use App\TimeTracking\Application\Command\WithdrawShiftChangeRequest\WithdrawShiftChangeRequestHandler;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestAlreadyDecidedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestWithdrawalForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestWithdrawnException;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(WithdrawShiftChangeRequestHandler::class)]
final class WithdrawShiftChangeRequestHandlerTest extends ShiftChangeRequestTestCase
{
    public function test_it_saves_the_requesters_withdrawal_at_now_and_changes_no_shift(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 08:00:00', null);
        $request = $this->newTimesRequest($this->employee, $shift, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        $this->withdraw($this->employee, $request->getId()->toRfc4122());

        self::assertSame(ShiftChangeRequestStatus::Withdrawn, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $request->getWithdrawnAt());
        self::assertSame(1, $this->requests->saveCallCount);
        self::assertTrue($shift->isOpen());
        self::assertSame([], $this->events->events);
    }

    public function test_it_lets_a_manager_withdraw_their_own_request(): void
    {
        $request = $this->missingShiftRequest($this->manager, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        $this->withdraw($this->manager, $request->getId()->toRfc4122());

        self::assertSame(ShiftChangeRequestStatus::Withdrawn, $request->getStatus());
    }

    public function test_it_refuses_owners_and_managers_and_hides_the_request_from_other_employees(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        foreach ([
            [$this->owner, ShiftChangeRequestWithdrawalForbiddenException::class],
            [$this->manager, ShiftChangeRequestWithdrawalForbiddenException::class],
            [$this->otherEmployee, ShiftChangeRequestNotFoundException::class],
        ] as [$actor, $expected]) {
            try {
                $this->withdraw($actor, $request->getId()->toRfc4122());
                self::fail('Someone else withdrew the request.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($expected, $e);
            }
        }

        self::assertSame(ShiftChangeRequestStatus::Pending, $request->getStatus());
        self::assertSame(0, $this->requests->saveCallCount);
    }

    public function test_it_refuses_a_request_that_is_no_longer_pending(): void
    {
        $rejected = $this->missingShiftRequest($this->employee, '2026-11-11 08:00:00', '2026-11-11 16:00:00');
        $rejected->reject($this->owner->getId(), MembershipRole::Owner, null, new \DateTimeImmutable(self::NOW));
        $withdrawn = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $withdrawn->withdraw($this->employee->getId(), new \DateTimeImmutable('2026-11-13 09:00:00'));

        foreach ([[$rejected, ShiftChangeRequestAlreadyDecidedException::class], [$withdrawn, ShiftChangeRequestWithdrawnException::class]] as [$request, $expected]) {
            try {
                $this->withdraw($this->employee, $request->getId()->toRfc4122());
                self::fail('A request that is no longer pending was withdrawn.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($expected, $e);
            }
        }

        self::assertEquals(new \DateTimeImmutable('2026-11-13 09:00:00'), $withdrawn->getWithdrawnAt());
        self::assertSame(0, $this->requests->saveCallCount);
    }

    public function test_it_refuses_a_deactivated_requester(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-13 09:00:00'));

        $this->expectException(OrganizationNotFoundException::class);

        $this->withdraw($this->employee, $request->getId()->toRfc4122());
    }

    public function test_it_answers_not_found_for_an_unknown_request(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $requestId) {
            try {
                $this->withdraw($this->employee, $requestId);
                self::fail(\sprintf('Request "%s" was found.', $requestId));
            } catch (ShiftChangeRequestNotFoundException) {
            }
        }

        self::assertSame(0, $this->requests->saveCallCount);
    }

    private function withdraw(User $actor, string $requestId): void
    {
        $handler = new WithdrawShiftChangeRequestHandler($this->requests, $this->queryBus, $this->clock);
        $handler(new WithdrawShiftChangeRequestCommand($this->organizationId(), $actor->getId()->toRfc4122(), $requestId));
    }
}
