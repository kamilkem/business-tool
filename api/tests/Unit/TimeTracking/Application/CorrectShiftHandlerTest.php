<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\TimeTracking\Application\Command\CorrectShift\CorrectShiftCommand;
use App\TimeTracking\Application\Command\CorrectShift\CorrectShiftHandler;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftOverlapException;
use App\TimeTracking\Domain\Model\Shift;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CorrectShiftHandler::class)]
final class CorrectShiftHandlerTest extends TestCase
{
    private const string NOW = '2026-11-13 12:00:00';

    private InMemoryShiftRepository $shifts;
    private InMemoryOrganizationRepository $organizations;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        $this->shifts = new InMemoryShiftRepository();
        $this->organizations = new InMemoryOrganizationRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: new \DateTimeImmutable('2026-01-05 09:00:00'));
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, new \DateTimeImmutable('2026-01-05 09:00:00'));
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, new \DateTimeImmutable('2026-01-05 09:00:00'));
        $this->organizations->add($this->organization);
    }

    public function test_it_ends_a_forgotten_shift_and_saves_it_with_the_correction(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);

        $this->handle($this->manager, $shift, '2026-11-12 16:00:00', '2026-11-12 23:30:00', 'Forgot to clock out');

        self::assertEquals(new \DateTimeImmutable('2026-11-12 23:30:00'), $shift->getEndedAt());
        self::assertSame('Forgot to clock out', $shift->getNote());
        self::assertTrue($this->manager->getId()->equals($shift->getCorrectedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $shift->getCorrectedAt());
        self::assertSame(1, $this->shifts->saveCallCount);
    }

    public function test_it_lets_an_owner_correct_their_own_shift(): void
    {
        $shift = $this->clocked($this->owner, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        $this->handle($this->owner, $shift, '2026-11-12 07:30:00', '2026-11-12 16:00:00');

        self::assertEquals(new \DateTimeImmutable('2026-11-12 07:30:00'), $shift->getStartedAt());
    }

    public function test_it_corrects_a_shift_of_a_deactivated_member(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-13 09:00:00'));

        $this->handle($this->manager, $shift, '2026-11-12 16:00:00', '2026-11-12 23:00:00');

        self::assertFalse($shift->isOpen());
    }

    public function test_it_checks_overlaps_with_the_persons_other_shifts_only(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 20:00:00');
        $this->clocked($this->manager, '2026-11-12 14:00:00', '2026-11-12 23:00:00');
        $this->clocked($this->employee, '2026-11-12 21:00:00', '2026-11-12 23:00:00');

        $this->handle($this->manager, $shift, '2026-11-12 15:00:00', '2026-11-12 21:00:00');
        self::assertEquals(new \DateTimeImmutable('2026-11-12 21:00:00'), $shift->getEndedAt());

        $this->expectException(ShiftOverlapException::class);
        $this->handle($this->manager, $shift, '2026-11-12 15:00:00', '2026-11-12 21:00:01');
    }

    public function test_it_refuses_to_reopen_an_ended_shift(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');

        $this->expectException(ShiftCannotBeReopenedException::class);

        $this->handle($this->manager, $shift, '2026-11-12 16:00:00', null);
    }

    public function test_it_refuses_an_employee_even_for_their_own_shift(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);

        try {
            $this->handle($this->employee, $shift, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
            self::fail('Expected ShiftChangeForbiddenException.');
        } catch (ShiftChangeForbiddenException) {
            self::assertTrue($shift->isOpen());
            self::assertSame(0, $this->shifts->saveCallCount);
        }
    }

    public function test_it_refuses_an_unknown_or_malformed_shift_id(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $shiftId) {
            try {
                $this->handleId($this->manager, $shiftId, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
                self::fail('Expected ShiftNotFoundException.');
            } catch (ShiftNotFoundException) {
                self::assertSame(0, $this->shifts->saveCallCount);
            }
        }
    }

    public function test_it_refuses_an_actor_who_is_not_an_active_member(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);
        OrganizationMother::deactivateMember($this->organization, $this->manager);

        $this->expectException(OrganizationNotFoundException::class);

        $this->handle($this->manager, $shift, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
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

    private function handle(User $actor, Shift $shift, string $startedAt, ?string $endedAt, ?string $note = null): void
    {
        $this->handleId($actor, $shift->getId()->toRfc4122(), $startedAt, $endedAt, $note);
    }

    private function handleId(User $actor, string $shiftId, string $startedAt, ?string $endedAt, ?string $note = null): void
    {
        $handler = new CorrectShiftHandler($this->shifts, OrganizationQueryBus::over($this->organizations), new MockClock(self::NOW));

        $handler(new CorrectShiftCommand(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $shiftId,
            $startedAt,
            $endedAt,
            $note,
        ));
    }
}
