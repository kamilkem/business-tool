<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\Payroll\Domain\Exception\InvalidPayRateException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Payroll\Domain\Model\HourlyRate;
use App\Payroll\Domain\Model\HourlyRateHistory;
use App\Payroll\Domain\Model\LockedPayrollDates;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Fixtures\PayRateMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** October 2026 is the locked month wherever locks are given. */
#[CoversClass(HourlyRate::class)]
#[CoversClass(HourlyRateHistory::class)]
#[CoversClass(LockedPayrollDates::class)]
final class HourlyRateTest extends TestCase
{
    private const string NOW = '2026-11-20 10:00:00';

    private Uuid $userId;
    private Uuid $actorId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v7();
        $this->actorId = Uuid::v7();
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_sets_a_rate_for_an_owner_or_manager(MembershipRole $role): void
    {
        $id = Uuid::v7();

        $rate = $this->set('2026-12-01', 3250, history: [], role: $role, id: $id);

        self::assertTrue($rate->getId()->equals($id));
        self::assertTrue($rate->getUserId()->equals($this->userId));
        self::assertSame('2026-12-01', $rate->getEffectiveFrom()->toString());
        self::assertSame(3250, $rate->getAmount());
        self::assertSame('PLN', $rate->getCurrency());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $rate->getCreatedAt());
        self::assertNull($rate->getUpdatedAt());
    }

    public function test_it_lets_an_owner_set_their_own_rate(): void
    {
        $rate = HourlyRate::set(Uuid::v7(), HourlyRateHistory::of($this->actorId, []), BusinessDate::of(2026, 12, 1), 3000, 'PLN', LockedPayrollDates::none(), $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        self::assertTrue($rate->getUserId()->equals($this->actorId));
    }

    public function test_it_refuses_an_employee(): void
    {
        $this->expectException(PayrollAccessForbiddenException::class);

        $this->set('2026-12-01', 3000, history: [], role: MembershipRole::Employee);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above an INTEGER' => [HourlyRate::MAX_AMOUNT + 1];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_refuses_an_amount_that_is_not_positive_or_too_large(int $amount): void
    {
        $this->expectException(InvalidPayRateException::class);

        $this->set('2026-12-01', $amount, history: []);
    }

    public function test_it_accepts_the_bounds_of_the_amount(): void
    {
        self::assertSame(1, $this->set('2026-12-01', 1, history: [])->getAmount());
        self::assertSame(HourlyRate::MAX_AMOUNT, $this->set('2026-12-02', HourlyRate::MAX_AMOUNT, history: [])->getAmount());
    }

    public function test_it_refuses_a_second_rate_from_the_same_date_and_allows_the_next_day(): void
    {
        $history = [PayRateMother::hourly($this->userId, '2026-12-01')];

        try {
            $this->set('2026-12-01', 3500, $history);
            self::fail('A second rate from the same date was set.');
        } catch (PayRateDateTakenException) {
        }

        self::assertSame('2026-12-02', $this->set('2026-12-02', 3500, $history)->getEffectiveFrom()->toString());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function lockedSets(): iterable
    {
        yield 'first day of the locked month' => ['2026-10-01', []];
        yield 'last day of the locked month' => ['2026-10-31', []];
        yield 'before the locked month, open-ended' => ['2026-09-15', []];
        yield 'before the locked month, next rate inside it' => ['2026-09-15', ['2026-10-10']];
        yield 'before the locked month, next rate after it' => ['2026-09-15', ['2026-11-01']];
    }

    /** @param list<string> $existing the dates of the person's other rates */
    #[DataProvider('lockedSets')]
    public function test_it_refuses_a_rate_in_effect_on_a_locked_date(string $effectiveFrom, array $existing): void
    {
        $this->expectException(PayRatePeriodLockedException::class);

        $this->set($effectiveFrom, 3000, $this->rates($existing), $this->octoberLocked());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function unlockedSets(): iterable
    {
        yield 'the day after the locked month' => ['2026-11-01', []];
        yield 'before the locked month, next rate on its first day' => ['2026-09-15', ['2026-10-01']];
        yield 'after the locked month, with an older rate in it' => ['2026-11-15', ['2026-10-10']];
    }

    /** @param list<string> $existing */
    #[DataProvider('unlockedSets')]
    public function test_it_sets_a_rate_whose_dates_are_all_open(string $effectiveFrom, array $existing): void
    {
        $rate = $this->set($effectiveFrom, 3000, $this->rates($existing), $this->octoberLocked());

        self::assertSame($effectiveFrom, $rate->getEffectiveFrom()->toString());
    }

    public function test_it_changes_the_date_and_the_amount_and_takes_the_organizations_currency(): void
    {
        $rate = PayRateMother::hourly($this->userId, '2026-12-01', 3000, 'EUR');

        $this->change($rate, '2026-12-05', 3300, [$rate], currency: 'PLN');

        self::assertSame('2026-12-05', $rate->getEffectiveFrom()->toString());
        self::assertSame(3300, $rate->getAmount());
        self::assertSame('PLN', $rate->getCurrency());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $rate->getUpdatedAt());
    }

    public function test_it_changes_nothing_when_nothing_differs_even_in_a_locked_month(): void
    {
        $rate = PayRateMother::hourly($this->userId, '2026-10-01', 3000);

        $this->change($rate, '2026-10-01', 3000, [$rate], $this->octoberLocked());

        self::assertNull($rate->getUpdatedAt());
    }

    public function test_it_refuses_to_move_a_rate_onto_another_rates_date(): void
    {
        $rate = PayRateMother::hourly($this->userId, '2026-12-01');
        $other = PayRateMother::hourly($this->userId, '2027-01-01');

        $this->expectException(PayRateDateTakenException::class);

        $this->change($rate, '2027-01-01', 3000, [$rate, $other]);
    }

    /** @return iterable<string, array{string, int, string, list<string>}> */
    public static function lockedChanges(): iterable
    {
        // [rate's date, new amount, new date, the person's other rates]
        yield 'amount of a rate in the locked month' => ['2026-10-10', 3500, '2026-10-10', []];
        yield 'amount of an earlier rate still in effect in it' => ['2026-09-01', 3500, '2026-09-01', []];
        yield 'moving a rate out of the locked month' => ['2026-10-10', 3000, '2026-11-10', ['2026-09-01']];
        yield 'moving a rate into the locked month' => ['2026-11-10', 3000, '2026-10-10', ['2026-09-01']];
        yield 'moving a later rate back over it' => ['2026-11-10', 3000, '2026-09-10', ['2026-09-01']];
    }

    /** @param list<string> $existing */
    #[DataProvider('lockedChanges')]
    public function test_it_refuses_a_change_that_alters_the_rate_of_a_locked_date(string $from, int $amount, string $to, array $existing): void
    {
        $rate = PayRateMother::hourly($this->userId, $from, 3000);

        $this->expectException(PayRatePeriodLockedException::class);

        $this->change($rate, $to, $amount, [$rate, ...$this->rates($existing)], $this->octoberLocked());
    }

    public function test_it_changes_a_rate_whose_old_and_new_dates_are_open_around_a_locked_month(): void
    {
        $september = PayRateMother::hourly($this->userId, '2026-09-01', 3000);
        $november = PayRateMother::hourly($this->userId, '2026-11-10', 3000);
        $october = PayRateMother::hourly($this->userId, '2026-10-01', 3000);

        $this->change($november, '2026-11-20', 3600, [$september, $october, $november], $this->octoberLocked());
        $this->change($september, '2026-09-05', 2900, [$september, $october, $november], $this->octoberLocked());

        self::assertSame('2026-11-20', $november->getEffectiveFrom()->toString());
        self::assertSame('2026-09-05', $september->getEffectiveFrom()->toString());
    }

    public function test_it_refuses_an_employee_to_change_or_delete(): void
    {
        $rate = PayRateMother::hourly($this->userId, '2026-12-01');

        try {
            $this->change($rate, '2026-12-01', 3500, [$rate], role: MembershipRole::Employee);
            self::fail('An employee changed a rate.');
        } catch (PayrollAccessForbiddenException) {
        }

        $this->expectException(PayrollAccessForbiddenException::class);
        $rate->delete(HourlyRateHistory::of($this->userId, [$rate]), LockedPayrollDates::none(), $this->actorId, MembershipRole::Employee);
    }

    public function test_it_refuses_to_delete_a_rate_in_effect_on_a_locked_date_and_deletes_the_next_one(): void
    {
        $september = PayRateMother::hourly($this->userId, '2026-09-01');
        $november = PayRateMother::hourly($this->userId, '2026-11-01');
        $history = HourlyRateHistory::of($this->userId, [$november, $september]);

        $november->delete($history, $this->octoberLocked(), $this->actorId, MembershipRole::Manager);

        $this->expectException(PayRatePeriodLockedException::class);
        $september->delete($history, $this->octoberLocked(), $this->actorId, MembershipRole::Manager);
    }

    public function test_it_knows_the_rate_in_effect_on_a_date_and_until_when(): void
    {
        $history = HourlyRateHistory::of($this->userId, $this->rates(['2026-11-01', '2026-03-01', '2026-06-15']));

        self::assertNull($history->inEffectOn(BusinessDate::of(2026, 2, 28)));
        self::assertSame('2026-03-01', $history->inEffectOn(BusinessDate::of(2026, 3, 1))?->getEffectiveFrom()->toString());
        self::assertSame('2026-03-01', $history->inEffectOn(BusinessDate::of(2026, 6, 14))?->getEffectiveFrom()->toString());
        self::assertSame('2026-06-15', $history->inEffectOn(BusinessDate::of(2026, 6, 15))?->getEffectiveFrom()->toString());
        self::assertSame('2026-11-01', $history->inEffectOn(BusinessDate::of(2030, 1, 1))?->getEffectiveFrom()->toString());

        self::assertSame('2026-06-14', $history->lastDateFrom(BusinessDate::of(2026, 3, 1))?->toString());
        self::assertSame('2026-10-31', $history->lastDateFrom(BusinessDate::of(2026, 6, 15))?->toString());
        self::assertNull($history->lastDateFrom(BusinessDate::of(2026, 11, 1)));
        self::assertSame(['2026-03-01', '2026-06-15', '2026-11-01'], array_map(static fn (HourlyRate $rate): string => $rate->getEffectiveFrom()->toString(), $history->all()));
    }

    public function test_it_refuses_a_history_with_someone_elses_rate(): void
    {
        $this->expectException(\LogicException::class);

        HourlyRateHistory::of($this->userId, [PayRateMother::hourly(Uuid::v7(), '2026-01-01')]);
    }

    /** @param list<HourlyRate> $history */
    private function set(string $effectiveFrom, int $amount, array $history, ?LockedPayrollDates $locks = null, MembershipRole $role = MembershipRole::Owner, ?Uuid $id = null): HourlyRate
    {
        return HourlyRate::set(
            $id ?? Uuid::v7(),
            HourlyRateHistory::of($this->userId, $history),
            BusinessDate::fromString($effectiveFrom),
            $amount,
            'PLN',
            $locks ?? LockedPayrollDates::none(),
            $this->actorId,
            $role,
            new \DateTimeImmutable(self::NOW),
        );
    }

    /** @param list<HourlyRate> $history */
    private function change(HourlyRate $rate, string $effectiveFrom, int $amount, array $history, ?LockedPayrollDates $locks = null, MembershipRole $role = MembershipRole::Owner, string $currency = 'PLN'): void
    {
        $rate->change(
            HourlyRateHistory::of($this->userId, $history),
            BusinessDate::fromString($effectiveFrom),
            $amount,
            $currency,
            $locks ?? LockedPayrollDates::none(),
            $this->actorId,
            $role,
            new \DateTimeImmutable(self::NOW),
        );
    }

    /**
     * @param list<string> $dates
     *
     * @return list<HourlyRate>
     */
    private function rates(array $dates): array
    {
        return array_map(fn (string $date): HourlyRate => PayRateMother::hourly($this->userId, $date), $dates);
    }

    private function octoberLocked(): LockedPayrollDates
    {
        return LockedPayrollDates::of([BusinessPeriod::month(2026, 10)]);
    }
}
