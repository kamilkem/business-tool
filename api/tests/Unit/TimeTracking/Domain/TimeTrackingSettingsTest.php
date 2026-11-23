<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\TimeTracking\Domain\Exception\TimeTrackingSettingsChangeForbiddenException;
use App\TimeTracking\Domain\Model\MaxShiftLength;
use App\TimeTracking\Domain\Model\TimeTrackingSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TimeTrackingSettings::class)]
#[CoversClass(TimeTrackingSettingsChangeForbiddenException::class)]
final class TimeTrackingSettingsTest extends TestCase
{
    public function test_it_is_created_and_changed_by_owners_and_managers(): void
    {
        $owner = Uuid::v7();
        $manager = Uuid::v7();
        $settings = TimeTrackingSettings::create(Uuid::v7(), MaxShiftLength::ofHours(10), $owner, MembershipRole::Owner, new \DateTimeImmutable('2026-11-22 10:00:00'));

        $settings->change(MaxShiftLength::ofHours(14), $manager, MembershipRole::Manager, new \DateTimeImmutable('2026-11-23 10:00:00'));

        self::assertSame(14, $settings->maxShiftLength()->hours);
        self::assertTrue($settings->getUpdatedBy()->equals($manager));
        self::assertEquals(new \DateTimeImmutable('2026-11-23 10:00:00'), $settings->getUpdatedAt());
    }

    public function test_it_keeps_who_changed_it_last_when_nothing_changes(): void
    {
        $owner = Uuid::v7();
        $settings = TimeTrackingSettings::create(Uuid::v7(), MaxShiftLength::ofHours(10), $owner, MembershipRole::Owner, new \DateTimeImmutable('2026-11-22 10:00:00'));

        $settings->change(MaxShiftLength::ofHours(10), Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable('2026-11-23 10:00:00'));

        self::assertTrue($settings->getUpdatedBy()->equals($owner));
        self::assertEquals(new \DateTimeImmutable('2026-11-22 10:00:00'), $settings->getUpdatedAt());
    }

    public function test_it_refuses_an_employee(): void
    {
        $employee = Uuid::v7();

        $this->expectException(TimeTrackingSettingsChangeForbiddenException::class);

        TimeTrackingSettings::create(Uuid::v7(), MaxShiftLength::ofHours(10), $employee, MembershipRole::Employee, new \DateTimeImmutable('2026-11-22 10:00:00'));
    }

    public function test_it_refuses_an_employee_changing_existing_settings(): void
    {
        $settings = TimeTrackingSettings::create(Uuid::v7(), MaxShiftLength::ofHours(10), Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-22 10:00:00'));

        try {
            $settings->change(MaxShiftLength::ofHours(14), $employee = Uuid::v7(), MembershipRole::Employee, new \DateTimeImmutable('2026-11-23 10:00:00'));
            self::fail('Expected TimeTrackingSettingsChangeForbiddenException.');
        } catch (TimeTrackingSettingsChangeForbiddenException $e) {
            self::assertSame('time_tracking.settings_change_forbidden', $e->errorCode());
            self::assertSame(['userId' => $employee->toRfc4122()], $e->errorParameters());
        }
        self::assertSame(10, $settings->maxShiftLength()->hours);
    }
}
