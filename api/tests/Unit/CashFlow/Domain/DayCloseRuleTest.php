<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Exception\InvalidDayCloseDateException;
use App\CashFlow\Domain\Model\DayCloseDateMode;
use App\CashFlow\Domain\Model\DayCloseRule;
use App\CashFlow\Domain\Model\DayEndTime;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * NOW is Saturday 21 November 2026, 02:30 in Warsaw (UTC+1): with the day ending at 05:00, a close
 * now belongs to Friday the 20th, while the calendar's today is the 21st.
 */
#[CoversClass(DayCloseRule::class)]
final class DayCloseRuleTest extends TestCase
{
    private const string NOW = '2026-11-21 01:30:00';

    public function test_it_defaults_to_the_day_end_time_at_five(): void
    {
        $rule = DayCloseRule::default();

        self::assertSame(DayCloseDateMode::DayEndTime, $rule->mode);
        self::assertSame('05:00', $rule->dayEndsAt->toString());
    }

    public function test_it_proposes_the_date_of_the_day_end_time_in_either_mode(): void
    {
        foreach (DayCloseDateMode::cases() as $mode) {
            self::assertSame('2026-11-20', self::rule($mode)->proposedDate(self::now(), self::warsaw())->toString(), $mode->value);
        }
    }

    /** @return iterable<string, array{DayCloseDateMode, MembershipRole, ?string, string}> mode, role, picked date, expected date */
    public static function allowedDates(): iterable
    {
        foreach (DayCloseDateMode::cases() as $mode) {
            foreach (MembershipRole::cases() as $role) {
                yield $mode->value.', '.$role->value.', no date picked' => [$mode, $role, null, '2026-11-20'];
            }
            foreach ([MembershipRole::Owner, MembershipRole::Manager] as $role) {
                yield $mode->value.', '.$role->value.', today' => [$mode, $role, '2026-11-21', '2026-11-21'];
                yield $mode->value.', '.$role->value.', a past date' => [$mode, $role, '2026-10-02', '2026-10-02'];
            }
        }
        yield 'day_end_time, an employee picking the proposed date' => [DayCloseDateMode::DayEndTime, MembershipRole::Employee, '2026-11-20', '2026-11-20'];
        yield 'employee_picks, an employee picking yesterday' => [DayCloseDateMode::EmployeePicks, MembershipRole::Employee, '2026-11-20', '2026-11-20'];
        yield 'employee_picks, an employee picking today' => [DayCloseDateMode::EmployeePicks, MembershipRole::Employee, '2026-11-21', '2026-11-21'];
    }

    #[DataProvider('allowedDates')]
    public function test_it_gives_the_date_of_a_close(DayCloseDateMode $mode, MembershipRole $role, ?string $picked, string $expected): void
    {
        $date = self::rule($mode)->dateOfClose(null === $picked ? null : BusinessDate::fromString($picked), $role, self::now(), self::warsaw());

        self::assertSame($expected, $date->toString());
    }

    /** @return iterable<string, array{DayCloseDateMode, MembershipRole, string, string, array<string, string>}> mode, role, picked date, code, parameters */
    public static function refusedDates(): iterable
    {
        foreach (DayCloseDateMode::cases() as $mode) {
            foreach ([MembershipRole::Owner, MembershipRole::Manager] as $role) {
                yield $mode->value.', '.$role->value.', tomorrow' => [$mode, $role, '2026-11-22', 'cash_flow.invalid_day_close_date.in_the_future', ['date' => '2026-11-22', 'today' => '2026-11-21']];
            }
        }
        yield 'day_end_time, an employee picking today' => [DayCloseDateMode::DayEndTime, MembershipRole::Employee, '2026-11-21', 'cash_flow.invalid_day_close_date.not_chosen_by_employee', ['date' => '2026-11-21', 'dayCloseDate' => '2026-11-20']];
        yield 'day_end_time, an employee picking a past date' => [DayCloseDateMode::DayEndTime, MembershipRole::Employee, '2026-11-19', 'cash_flow.invalid_day_close_date.not_chosen_by_employee', ['date' => '2026-11-19', 'dayCloseDate' => '2026-11-20']];
        yield 'employee_picks, an employee picking the day before yesterday' => [DayCloseDateMode::EmployeePicks, MembershipRole::Employee, '2026-11-19', 'cash_flow.invalid_day_close_date.not_today_or_yesterday', ['date' => '2026-11-19', 'today' => '2026-11-21']];
        yield 'employee_picks, an employee picking tomorrow' => [DayCloseDateMode::EmployeePicks, MembershipRole::Employee, '2026-11-22', 'cash_flow.invalid_day_close_date.not_today_or_yesterday', ['date' => '2026-11-22', 'today' => '2026-11-21']];
    }

    /** @param array<string, string> $parameters */
    #[DataProvider('refusedDates')]
    public function test_it_refuses_a_date_the_setting_does_not_allow(DayCloseDateMode $mode, MembershipRole $role, string $picked, string $code, array $parameters): void
    {
        try {
            self::rule($mode)->dateOfClose(BusinessDate::fromString($picked), $role, self::now(), self::warsaw());
            self::fail('Expected an InvalidDayCloseDateException.');
        } catch (InvalidDayCloseDateException $e) {
            self::assertSame($code, $e->errorCode());
            self::assertSame($parameters, $e->errorParameters());
        }
    }

    public function test_it_takes_today_and_yesterday_from_the_calendar_in_the_organizations_time_zone(): void
    {
        // 23:30 UTC on the 21st is already Sunday the 22nd in Warsaw, still Saturday the 21st in UTC.
        $now = new MockClock('2026-11-21 23:30:00', 'UTC')->now();
        $rule = self::rule(DayCloseDateMode::EmployeePicks);

        self::assertSame('2026-11-21', $rule->dateOfClose(BusinessDate::fromString('2026-11-21'), MembershipRole::Employee, $now, self::warsaw())->toString());
        self::assertSame('2026-11-22', $rule->dateOfClose(BusinessDate::fromString('2026-11-22'), MembershipRole::Manager, $now, self::warsaw())->toString());
        $this->expectException(InvalidDayCloseDateException::class);
        $rule->dateOfClose(BusinessDate::fromString('2026-11-20'), MembershipRole::Employee, $now, self::warsaw());
    }

    public function test_it_compares_rules_by_mode_and_time(): void
    {
        $rule = new DayCloseRule(DayCloseDateMode::EmployeePicks, DayEndTime::fromString('04:00'));

        self::assertTrue($rule->equals(new DayCloseRule(DayCloseDateMode::EmployeePicks, DayEndTime::fromString('04:00'))));
        self::assertFalse($rule->equals(new DayCloseRule(DayCloseDateMode::DayEndTime, DayEndTime::fromString('04:00'))));
        self::assertFalse($rule->equals(new DayCloseRule(DayCloseDateMode::EmployeePicks, DayEndTime::fromString('05:00'))));
    }

    private static function rule(DayCloseDateMode $mode): DayCloseRule
    {
        return new DayCloseRule($mode, DayEndTime::default());
    }

    private static function now(): \DateTimeImmutable
    {
        return new MockClock(self::NOW, 'UTC')->now();
    }

    private static function warsaw(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Warsaw');
    }
}
