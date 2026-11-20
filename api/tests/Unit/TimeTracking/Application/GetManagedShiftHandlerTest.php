<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\TimeTracking\Application\Query\GetManagedShift\GetManagedShiftHandler;
use App\TimeTracking\Application\Query\GetManagedShift\GetManagedShiftQuery;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetManagedShiftHandler::class)]
#[CoversClass(ShiftView::class)]
final class GetManagedShiftHandlerTest extends TestCase
{
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

    public function test_it_returns_anyones_shift_with_its_correction_to_owners_and_managers(): void
    {
        $shift = Shift::record(
            Uuid::v7(),
            $this->employee->getId(),
            new \DateTimeImmutable('2026-11-12 16:00:00'),
            new \DateTimeImmutable('2026-11-12 23:00:00'),
            'No phone',
            $this->manager->getId(),
            MembershipRole::Manager,
            [],
            new \DateTimeImmutable('2026-11-13 09:00:00'),
            ShiftPeriodLocks::none(),
        );
        $this->shifts->add($shift);

        foreach ([$this->owner, $this->manager] as $actor) {
            self::assertEquals(new ShiftView(
                $shift->getId()->toRfc4122(),
                $this->employee->getId()->toRfc4122(),
                new \DateTimeImmutable('2026-11-12 16:00:00'),
                new \DateTimeImmutable('2026-11-12 23:00:00'),
                'No phone',
                'manager',
                $this->manager->getId()->toRfc4122(),
                new \DateTimeImmutable('2026-11-13 09:00:00'),
            ), $this->ask($actor, $shift->getId()->toRfc4122()));
        }
    }

    public function test_it_returns_null_for_an_unknown_or_malformed_shift_id(): void
    {
        self::assertNull($this->ask($this->manager, Uuid::v7()->toRfc4122()));
        self::assertNull($this->ask($this->manager, 'not-a-uuid'));
    }

    public function test_it_returns_null_for_an_actor_who_is_not_an_active_member(): void
    {
        $shift = Shift::clockIn(Uuid::v7(), $this->employee->getId(), new \DateTimeImmutable('2026-11-12 16:00:00'), null);
        $this->shifts->add($shift);
        OrganizationMother::deactivateMember($this->organization, $this->manager);

        self::assertNull($this->ask($this->manager, $shift->getId()->toRfc4122()));
        self::assertNull($this->ask(UserMother::any('stranger@example.com'), $shift->getId()->toRfc4122()));
    }

    public function test_it_refuses_an_employee_even_for_their_own_shift(): void
    {
        $shift = Shift::clockIn(Uuid::v7(), $this->employee->getId(), new \DateTimeImmutable('2026-11-12 16:00:00'), null);
        $this->shifts->add($shift);

        $this->expectException(ShiftChangeForbiddenException::class);

        $this->ask($this->employee, $shift->getId()->toRfc4122());
    }

    private function ask(User $actor, string $shiftId): ?ShiftView
    {
        $handler = new GetManagedShiftHandler($this->shifts, OrganizationQueryBus::over($this->organizations));

        return $handler(new GetManagedShiftQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $shiftId));
    }
}
