<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\TimeTracking\Application\Command\DeleteShift\DeleteShiftCommand;
use App\TimeTracking\Application\Command\DeleteShift\DeleteShiftHandler;
use App\TimeTracking\Application\ShiftPeriodLocksLoader;
use App\TimeTracking\Domain\Exception\OpenShiftCannotBeDeletedException;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftHasPendingChangeRequestException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftPeriodLockedException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DeleteShiftHandler::class)]
final class DeleteShiftHandlerTest extends TestCase
{
    private const string NOW = '2026-11-13 12:00:00';

    private InMemoryShiftRepository $shifts;
    private InMemoryShiftChangeRequestRepository $requests;
    private InMemoryLockedShiftPeriodRepository $lockedPeriods;
    private InMemoryOrganizationRepository $organizations;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        $this->shifts = new InMemoryShiftRepository();
        $this->requests = new InMemoryShiftChangeRequestRepository();
        $this->lockedPeriods = new InMemoryLockedShiftPeriodRepository();
        $this->organizations = new InMemoryOrganizationRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: new \DateTimeImmutable('2026-01-05 09:00:00'));
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, new \DateTimeImmutable('2026-01-05 09:00:00'));
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, new \DateTimeImmutable('2026-01-05 09:00:00'));
        $this->organizations->add($this->organization);
    }

    public function test_it_removes_an_ended_shift_and_leaves_the_others(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        $other = $this->clocked($this->employee, '2026-11-11 16:00:00', '2026-11-11 23:00:00');

        $this->handle($this->manager, $shift->getId()->toRfc4122());

        self::assertSame([$other], $this->shifts->all());
        self::assertSame(1, $this->shifts->removeCallCount);
    }

    public function test_it_lets_an_owner_delete_their_own_shift(): void
    {
        $shift = $this->clocked($this->owner, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        $this->handle($this->owner, $shift->getId()->toRfc4122());

        self::assertSame([], $this->shifts->all());
    }

    public function test_it_deletes_a_shift_of_a_deactivated_member(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-13 09:00:00'));

        $this->handle($this->manager, $shift->getId()->toRfc4122());

        self::assertSame([], $this->shifts->all());
    }

    public function test_it_deletes_a_shift_whose_change_requests_are_decided(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        $request = $this->requestNewTimes($shift);
        $request->reject($this->manager->getId(), MembershipRole::Manager, 'No', new \DateTimeImmutable(self::NOW));

        $this->handle($this->manager, $shift->getId()->toRfc4122());

        self::assertSame([], $this->shifts->all());
        self::assertSame([$request], $this->requests->all());
    }

    public function test_it_refuses_an_employee_before_looking_the_shift_up(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');

        foreach ([$shift->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $shiftId) {
            try {
                $this->handle($this->employee, $shiftId);
                self::fail('Expected ShiftChangeForbiddenException.');
            } catch (ShiftChangeForbiddenException) {
                self::assertSame(0, $this->shifts->removeCallCount);
            }
        }
    }

    public function test_it_refuses_an_unknown_or_malformed_shift_id(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $shiftId) {
            try {
                $this->handle($this->manager, $shiftId);
                self::fail('Expected ShiftNotFoundException.');
            } catch (ShiftNotFoundException) {
                self::assertSame(0, $this->shifts->removeCallCount);
            }
        }
    }

    public function test_it_refuses_an_actor_who_is_not_an_active_member(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        OrganizationMother::deactivateMember($this->organization, $this->manager);

        $this->expectException(OrganizationNotFoundException::class);

        $this->handle($this->manager, $shift->getId()->toRfc4122());
    }

    public function test_it_refuses_to_delete_an_open_shift(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-13 08:00:00', null);

        $this->expectException(OpenShiftCannotBeDeletedException::class);

        $this->handle($this->manager, $shift->getId()->toRfc4122());
    }

    public function test_it_refuses_to_delete_a_shift_of_a_locked_month_and_keeps_it(): void
    {
        $shift = $this->clocked($this->employee, '2026-10-31 19:00:00', '2026-11-01 02:00:00');
        $this->lockedPeriods->lockMonth(2026, 10);

        try {
            $this->handle($this->owner, $shift->getId()->toRfc4122());
            self::fail('Expected ShiftPeriodLockedException.');
        } catch (ShiftPeriodLockedException) {
            self::assertSame([$shift], $this->shifts->all());
        }
    }

    public function test_it_refuses_to_delete_a_shift_with_a_pending_change_request_and_keeps_it(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        $this->requestNewTimes($shift);

        try {
            $this->handle($this->manager, $shift->getId()->toRfc4122());
            self::fail('Expected ShiftHasPendingChangeRequestException.');
        } catch (ShiftHasPendingChangeRequestException) {
            self::assertSame([$shift], $this->shifts->all());
        }
    }

    public function test_it_is_not_held_up_by_a_pending_request_about_another_shift(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        $this->requestNewTimes($this->clocked($this->employee, '2026-11-11 16:00:00', '2026-11-11 23:00:00'));

        $this->handle($this->manager, $shift->getId()->toRfc4122());

        self::assertCount(1, $this->shifts->all());
    }

    private function clocked(User $user, string $startedAt, ?string $endedAt): Shift
    {
        $shift = Shift::clockIn(Uuid::v7(), $user->getId(), new \DateTimeImmutable($startedAt), null);
        if (null !== $endedAt) {
            $shift->clockOut(new \DateTimeImmutable($endedAt));
        }
        $this->shifts->add($shift);

        return $shift;
    }

    private function requestNewTimes(Shift $shift): ShiftChangeRequest
    {
        $request = ShiftChangeRequest::forShift(
            Uuid::v7(),
            $shift->getUserId(),
            $shift,
            $shift->getStartedAt()->modify('-30 minutes'),
            $shift->getEndedAt(),
            'Started earlier',
            new \DateTimeImmutable(self::NOW),
            ShiftPeriodLocks::none(),
        );
        $this->requests->add($request);

        return $request;
    }

    private function handle(User $actor, string $shiftId): void
    {
        $queryBus = OrganizationQueryBus::over($this->organizations);
        $handler = new DeleteShiftHandler($this->shifts, $this->requests, $queryBus, new ShiftPeriodLocksLoader($this->lockedPeriods, $queryBus));

        $handler(new DeleteShiftCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $shiftId));
    }
}
