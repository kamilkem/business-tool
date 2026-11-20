<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\Payroll\Domain\Exception\InvalidPayRateException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Payroll\Domain\Model\DayRate;
use App\Payroll\Domain\Model\LockedPayrollDates;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Fixtures\PayRateMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** October 2026 is the locked month wherever locks are given. */
#[CoversClass(DayRate::class)]
final class DayRateTest extends TestCase
{
    private const string NOW = '2026-11-20 10:00:00';

    private Uuid $userId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v7();
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_sets_a_day_rate_for_an_owner_or_manager(MembershipRole $role): void
    {
        $dayRate = $this->set('2026-12-31', 4500, role: $role);

        self::assertTrue($dayRate->getUserId()->equals($this->userId));
        self::assertSame('2026-12-31', $dayRate->getDate()->toString());
        self::assertSame(4500, $dayRate->getAmount());
        self::assertSame('PLN', $dayRate->getCurrency());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $dayRate->getCreatedAt());
        self::assertNull($dayRate->getUpdatedAt());
    }

    public function test_it_refuses_an_employee(): void
    {
        $this->expectException(PayrollAccessForbiddenException::class);

        $this->set('2026-12-31', 4500, role: MembershipRole::Employee);
    }

    public function test_it_refuses_an_amount_that_is_not_positive(): void
    {
        $this->expectException(InvalidPayRateException::class);

        $this->set('2026-12-31', 0);
    }

    public function test_it_refuses_a_second_day_rate_on_the_same_date(): void
    {
        $this->expectException(PayRateDateTakenException::class);

        $this->set('2026-12-31', 4500, sameDate: PayRateMother::day($this->userId, '2026-12-31'));
    }

    /** @return iterable<string, array{string}> */
    public static function lockedDates(): iterable
    {
        yield 'first day' => ['2026-10-01'];
        yield 'last day' => ['2026-10-31'];
    }

    #[DataProvider('lockedDates')]
    public function test_it_refuses_a_day_rate_on_a_locked_date(string $date): void
    {
        $this->expectException(PayRatePeriodLockedException::class);

        $this->set($date, 4500, locks: $this->octoberLocked());
    }

    public function test_it_sets_a_day_rate_on_the_days_around_a_locked_month(): void
    {
        self::assertSame('2026-09-30', $this->set('2026-09-30', 4500, locks: $this->octoberLocked())->getDate()->toString());
        self::assertSame('2026-11-01', $this->set('2026-11-01', 4500, locks: $this->octoberLocked())->getDate()->toString());
    }

    public function test_it_changes_the_date_and_the_amount(): void
    {
        $dayRate = PayRateMother::day($this->userId, '2026-12-24', 4500, 'EUR');

        $this->change($dayRate, '2026-12-31', 5000);

        self::assertSame('2026-12-31', $dayRate->getDate()->toString());
        self::assertSame(5000, $dayRate->getAmount());
        self::assertSame('PLN', $dayRate->getCurrency());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $dayRate->getUpdatedAt());
    }

    public function test_it_changes_nothing_when_nothing_differs_even_on_a_locked_date(): void
    {
        $dayRate = PayRateMother::day($this->userId, '2026-10-31', 4500);

        $this->change($dayRate, '2026-10-31', 4500, locks: $this->octoberLocked());

        self::assertNull($dayRate->getUpdatedAt());
    }

    public function test_it_refuses_to_move_a_day_rate_onto_another_ones_date(): void
    {
        $dayRate = PayRateMother::day($this->userId, '2026-12-24');

        $this->expectException(PayRateDateTakenException::class);

        $this->change($dayRate, '2026-12-31', 4500, sameDate: PayRateMother::day($this->userId, '2026-12-31'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function lockedChanges(): iterable
    {
        yield 'amount on a locked date' => ['2026-10-31', '2026-10-31', 5000];
        yield 'out of the locked month' => ['2026-10-31', '2026-11-01', 4500];
        yield 'into the locked month' => ['2026-11-01', '2026-10-31', 4500];
    }

    #[DataProvider('lockedChanges')]
    public function test_it_refuses_a_change_from_or_to_a_locked_date(string $from, string $to, int $amount): void
    {
        $dayRate = PayRateMother::day($this->userId, $from, 4500);

        $this->expectException(PayRatePeriodLockedException::class);

        $this->change($dayRate, $to, $amount, locks: $this->octoberLocked());
    }

    public function test_it_deletes_only_a_day_rate_on_an_open_date(): void
    {
        PayRateMother::day($this->userId, '2026-11-01')->delete($this->octoberLocked(), Uuid::v7(), MembershipRole::Manager);

        $this->expectException(PayRatePeriodLockedException::class);
        PayRateMother::day($this->userId, '2026-10-31')->delete($this->octoberLocked(), Uuid::v7(), MembershipRole::Manager);
    }

    public function test_it_refuses_an_employee_to_delete(): void
    {
        $this->expectException(PayrollAccessForbiddenException::class);

        PayRateMother::day($this->userId, '2026-11-01')->delete(LockedPayrollDates::none(), Uuid::v7(), MembershipRole::Employee);
    }

    private function set(string $date, int $amount, ?DayRate $sameDate = null, ?LockedPayrollDates $locks = null, MembershipRole $role = MembershipRole::Owner): DayRate
    {
        return DayRate::set(Uuid::v7(), $this->userId, BusinessDate::fromString($date), $amount, 'PLN', $sameDate, $locks ?? LockedPayrollDates::none(), Uuid::v7(), $role, new \DateTimeImmutable(self::NOW));
    }

    private function change(DayRate $dayRate, string $date, int $amount, ?DayRate $sameDate = null, ?LockedPayrollDates $locks = null): void
    {
        $dayRate->change(BusinessDate::fromString($date), $amount, 'PLN', $sameDate, $locks ?? LockedPayrollDates::none(), Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    private function octoberLocked(): LockedPayrollDates
    {
        return LockedPayrollDates::of([BusinessPeriod::month(2026, 10)]);
    }
}
