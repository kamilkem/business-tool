<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\TimeTracking\Application\Command\AddShift\AddShiftCommand;
use App\TimeTracking\Application\Command\AddShift\AddShiftHandler;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftOverlapException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftSource;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AddShiftHandler::class)]
final class AddShiftHandlerTest extends TestCase
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

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_records_the_shift_entered_by_an_owner_or_manager_at_the_clocks_now(string $actor): void
    {
        $actor = 'owner' === $actor ? $this->owner : $this->manager;
        $shiftId = Uuid::v7()->toRfc4122();

        $this->handle($actor, $this->employee, '2026-11-12T16:00:00+01:00', '2026-11-12T23:00:00+01:00', 'No phone', $shiftId);

        $shift = $this->shifts->findById(Uuid::fromString($shiftId));
        self::assertNotNull($shift);
        self::assertTrue($shift->getUserId()->equals($this->employee->getId()));
        self::assertEquals(new \DateTimeImmutable('2026-11-12 15:00:00', new \DateTimeZone('UTC')), $shift->getStartedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 22:00:00', new \DateTimeZone('UTC')), $shift->getEndedAt());
        self::assertSame('No phone', $shift->getNote());
        self::assertSame(ShiftSource::Manager, $shift->getSource());
        self::assertTrue($actor->getId()->equals($shift->getCorrectedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $shift->getCorrectedAt());
    }

    public function test_it_records_a_shift_of_the_actor_themselves(): void
    {
        $this->handle($this->manager, $this->manager, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        self::assertCount(1, $this->shifts->all());
    }

    public function test_it_records_a_shift_of_a_deactivated_member(): void
    {
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-12 23:30:00'));

        $this->handle($this->manager, $this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');

        self::assertCount(1, $this->shifts->all());
    }

    public function test_it_refuses_an_employee_even_for_their_own_shift(): void
    {
        $this->expectException(ShiftChangeForbiddenException::class);

        $this->handle($this->employee, $this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
    }

    public function test_it_refuses_a_person_who_is_not_a_member(): void
    {
        $this->expectException(MemberNotFoundException::class);

        $this->handle($this->manager, UserMother::any('stranger@example.com'), '2026-11-12 16:00:00', '2026-11-12 23:00:00');
    }

    public function test_it_refuses_an_actor_who_is_not_an_active_member(): void
    {
        OrganizationMother::deactivateMember($this->organization, $this->manager);

        $this->expectException(OrganizationNotFoundException::class);

        $this->handle($this->manager, $this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
    }

    public function test_it_refuses_a_shift_that_overlaps_one_the_person_clocked(): void
    {
        $clocked = Shift::clockIn(Uuid::v7(), $this->employee->getId(), new \DateTimeImmutable('2026-11-12 18:00:00'), null);
        $this->shifts->add($clocked);

        try {
            $this->handle($this->manager, $this->employee, '2026-11-12 16:00:00', '2026-11-12 18:00:01');
            self::fail('Expected ShiftOverlapException.');
        } catch (ShiftOverlapException) {
            self::assertCount(1, $this->shifts->all());
        }

        $this->handle($this->manager, $this->employee, '2026-11-12 16:00:00', '2026-11-12 18:00:00');
        self::assertCount(2, $this->shifts->all());
    }

    public function test_it_does_not_mind_another_persons_shift_at_the_same_time(): void
    {
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $this->manager->getId(), new \DateTimeImmutable('2026-11-12 15:00:00'), null));

        $this->handle($this->manager, $this->employee, '2026-11-12 16:00:00', '2026-11-12 23:00:00');

        self::assertCount(2, $this->shifts->all());
    }

    public function test_it_refuses_a_shift_that_ends_after_the_clocks_now(): void
    {
        $this->expectException(ShiftInTheFutureException::class);

        $this->handle($this->manager, $this->employee, '2026-11-13 08:00:00', '2026-11-13 12:00:01');
    }

    private function handle(User $actor, User $user, string $startedAt, string $endedAt, ?string $note = null, ?string $shiftId = null): void
    {
        $handler = new AddShiftHandler($this->shifts, OrganizationQueryBus::over($this->organizations), new MockClock(self::NOW));

        $handler(new AddShiftCommand(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $shiftId ?? Uuid::v7()->toRfc4122(),
            $user->getId()->toRfc4122(),
            $startedAt,
            $endedAt,
            $note,
        ));
    }
}
