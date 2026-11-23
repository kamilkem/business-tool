<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\TimeTracking\Application\Command\ChangeTimeTrackingSettings\ChangeTimeTrackingSettingsCommand;
use App\TimeTracking\Application\Command\ChangeTimeTrackingSettings\ChangeTimeTrackingSettingsHandler;
use App\TimeTracking\Application\MaxShiftLengths;
use App\TimeTracking\Application\Query\GetTimeTrackingSettings\GetTimeTrackingSettingsHandler;
use App\TimeTracking\Application\Query\GetTimeTrackingSettings\GetTimeTrackingSettingsQuery;
use App\TimeTracking\Application\Query\GetTimeTrackingSettings\TimeTrackingSettingsView;
use App\TimeTracking\Domain\Exception\InvalidMaxShiftLengthException;
use App\TimeTracking\Domain\Exception\TimeTrackingSettingsChangeForbiddenException;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

/** The organization of ShiftChangeRequestTestCase: an owner, a manager and two employees. */
#[CoversClass(ChangeTimeTrackingSettingsHandler::class)]
#[CoversClass(GetTimeTrackingSettingsHandler::class)]
#[CoversClass(MaxShiftLengths::class)]
final class TimeTrackingSettingsHandlersTest extends ShiftChangeRequestTestCase
{
    private InMemoryTimeTrackingSettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new InMemoryTimeTrackingSettingsRepository();
    }

    public function test_it_gives_the_defaults_to_every_active_member_until_changed(): void
    {
        foreach ([$this->owner, $this->manager, $this->employee] as $member) {
            self::assertEquals(new TimeTrackingSettingsView(12, null), $this->get($member));
        }
        self::assertSame(12, (new MaxShiftLengths($this->settings))->current()->hours);
    }

    public function test_it_creates_the_settings_on_the_first_change_and_changes_them_after(): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->change($this->manager, 10, $id);

        self::assertEquals(new TimeTrackingSettingsView(10, new \DateTimeImmutable(self::NOW)), $this->get($this->employee));
        self::assertSame($id, $this->settings->findCurrent()?->getId()->toRfc4122());

        $this->clock->modify('+1 day');
        $this->change($this->owner, 16);

        self::assertEquals(new TimeTrackingSettingsView(16, new \DateTimeImmutable('2026-11-14 12:00:00')), $this->get($this->manager));
        self::assertSame($id, $this->settings->findCurrent()?->getId()->toRfc4122(), 'The same settings, changed.');
        self::assertSame(1, $this->settings->saves);
        self::assertSame(16, (new MaxShiftLengths($this->settings))->current()->hours);
    }

    public function test_it_refuses_an_employee_to_change_or_to_manage_them(): void
    {
        try {
            $this->change($this->employee, 10);
            self::fail('Expected TimeTrackingSettingsChangeForbiddenException.');
        } catch (TimeTrackingSettingsChangeForbiddenException) {
        }
        self::assertNull($this->settings->findCurrent());

        $this->expectException(TimeTrackingSettingsChangeForbiddenException::class);
        $this->handler()(new GetTimeTrackingSettingsQuery($this->organizationId(), $this->employee->getId()->toRfc4122(), toManage: true));
    }

    public function test_it_refuses_hours_out_of_range(): void
    {
        $this->expectException(InvalidMaxShiftLengthException::class);

        $this->change($this->owner, 25);
    }

    public function test_it_treats_a_non_member_as_not_found(): void
    {
        $stranger = UserMother::any('stranger@example.com');
        $other = OrganizationMother::ownedBy($stranger, 'Other Bar');
        $this->organizations->add($other);
        OrganizationMother::deactivateMember($this->organization, $this->otherEmployee);

        self::assertNull($this->get($stranger));
        self::assertNull($this->get($this->otherEmployee), 'A deactivated member sees nothing.');

        $this->expectException(OrganizationNotFoundException::class);
        $this->change($stranger, 10);
    }

    private function get(User $actor): ?TimeTrackingSettingsView
    {
        return $this->handler()(new GetTimeTrackingSettingsQuery($this->organizationId(), $actor->getId()->toRfc4122()));
    }

    private function handler(): GetTimeTrackingSettingsHandler
    {
        return new GetTimeTrackingSettingsHandler($this->settings, $this->queryBus);
    }

    private function change(User $actor, int $hours, ?string $id = null): void
    {
        (new ChangeTimeTrackingSettingsHandler($this->settings, $this->queryBus, $this->clock))(
            new ChangeTimeTrackingSettingsCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id ?? Uuid::v7()->toRfc4122(), $hours),
        );
    }
}
