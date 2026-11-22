<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Model\DayCloseDateMode;
use App\CashFlow\Domain\Model\DayCloseRule;
use App\CashFlow\Domain\Model\DayCloseSettings;
use App\CashFlow\Domain\Model\DayEndTime;
use App\Organization\Domain\Model\MembershipRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DayCloseSettings::class)]
final class DayCloseSettingsTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    public function test_it_creates_the_settings_with_who_set_them_and_when(): void
    {
        $id = Uuid::v7();
        $manager = Uuid::v7();

        $settings = DayCloseSettings::create($id, self::picks('04:30'), $manager, MembershipRole::Manager, new \DateTimeImmutable(self::NOW));

        self::assertTrue($settings->getId()->equals($id));
        self::assertTrue($settings->rule()->equals(self::picks('04:30')));
        self::assertTrue($settings->getUpdatedBy()->equals($manager));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $settings->getUpdatedAt());
    }

    public function test_it_changes_the_rule_and_records_who_changed_it(): void
    {
        $settings = DayCloseSettings::create(Uuid::v7(), DayCloseRule::default(), Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
        $manager = Uuid::v7();

        $settings->change(self::picks('06:00'), $manager, MembershipRole::Manager, new \DateTimeImmutable('2026-11-23 08:00:00'));

        self::assertTrue($settings->rule()->equals(self::picks('06:00')));
        self::assertTrue($settings->getUpdatedBy()->equals($manager));
        self::assertEquals(new \DateTimeImmutable('2026-11-23 08:00:00'), $settings->getUpdatedAt());
    }

    public function test_it_changes_nothing_for_the_current_rule(): void
    {
        $owner = Uuid::v7();
        $settings = DayCloseSettings::create(Uuid::v7(), self::picks('04:30'), $owner, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        $settings->change(self::picks('04:30'), Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable('2026-11-23 08:00:00'));

        self::assertTrue($settings->getUpdatedBy()->equals($owner));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $settings->getUpdatedAt());
    }

    public function test_it_refuses_employees(): void
    {
        $settings = DayCloseSettings::create(Uuid::v7(), DayCloseRule::default(), Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        try {
            $settings->change(self::picks('04:30'), Uuid::v7(), MembershipRole::Employee, new \DateTimeImmutable(self::NOW));
            self::fail('Expected a CashFlowAccessForbiddenException.');
        } catch (CashFlowAccessForbiddenException $e) {
            self::assertSame('cash_flow.access_forbidden.to_manage', $e->errorCode());
        }
        self::assertTrue($settings->rule()->equals(DayCloseRule::default()));

        $this->expectException(CashFlowAccessForbiddenException::class);
        DayCloseSettings::create(Uuid::v7(), DayCloseRule::default(), Uuid::v7(), MembershipRole::Employee, new \DateTimeImmutable(self::NOW));
    }

    private static function picks(string $dayEndsAt): DayCloseRule
    {
        return new DayCloseRule(DayCloseDateMode::EmployeePicks, DayEndTime::fromString($dayEndsAt));
    }
}
