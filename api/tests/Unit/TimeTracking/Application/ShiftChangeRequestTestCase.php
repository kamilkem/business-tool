<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\User\Domain\Model\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * One organization (Europe/Warsaw) with an owner, a manager and two employees, over in-memory
 * repositories and the Organization module's real queries; the clock stands at NOW (UTC).
 */
abstract class ShiftChangeRequestTestCase extends TestCase
{
    protected const string NOW = '2026-11-13 12:00:00';

    protected InMemoryShiftChangeRequestRepository $requests;
    protected InMemoryShiftRepository $shifts;
    protected InMemoryLockedShiftPeriodRepository $lockedPeriods;
    protected InMemoryOrganizationRepository $organizations;
    protected RecordingEventPublisher $events;
    protected StubQueryBus $queryBus;
    protected MockClock $clock;
    protected Organization $organization;
    protected User $owner;
    protected User $manager;
    protected User $employee;
    protected User $otherEmployee;

    protected function setUp(): void
    {
        $this->requests = new InMemoryShiftChangeRequestRepository();
        $this->shifts = new InMemoryShiftRepository();
        $this->lockedPeriods = new InMemoryLockedShiftPeriodRepository();
        $this->organizations = new InMemoryOrganizationRepository();
        $this->events = new RecordingEventPublisher();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $this->queryBus = OrganizationQueryBus::over($this->organizations);

        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->otherEmployee = UserMother::any('adam@example.com');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->otherEmployee, MembershipRole::Employee, $joined);
        $this->organizations->add($this->organization);
    }

    protected function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    /** A shift the person clocked (UTC times), stored. */
    protected function clocked(User $user, string $start, ?string $end): Shift
    {
        $shift = Shift::clockIn(Uuid::v7(), $user->getId(), new \DateTimeImmutable($start), null);
        if (null !== $end) {
            $shift->clockOut(new \DateTimeImmutable($end));
        }
        $this->shifts->add($shift);

        return $shift;
    }

    /** A pending request for a missing shift (UTC times), stored. */
    protected function missingShiftRequest(User $user, string $start, string $end, string $note = 'My phone was dead', string $submittedAt = '2026-11-13 08:00:00'): ShiftChangeRequest
    {
        $request = ShiftChangeRequest::forMissingShift(Uuid::v7(), $user->getId(), new \DateTimeImmutable($start), new \DateTimeImmutable($end), $note, new \DateTimeImmutable($submittedAt), ShiftPeriodLocks::none());
        $this->requests->add($request);

        return $request;
    }

    /** A pending request for new times (UTC) for one of the person's shifts, stored. */
    protected function newTimesRequest(User $user, Shift $shift, string $start, ?string $end, string $note = 'Forgot to clock out', string $submittedAt = '2026-11-13 08:00:00'): ShiftChangeRequest
    {
        $request = ShiftChangeRequest::forShift(Uuid::v7(), $user->getId(), $shift, new \DateTimeImmutable($start), null === $end ? null : new \DateTimeImmutable($end), $note, new \DateTimeImmutable($submittedAt), ShiftPeriodLocks::none());
        $this->requests->add($request);

        return $request;
    }
}
