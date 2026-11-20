<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\Payroll\Domain\Exception\InvalidPaycheckAdjustmentException;
use App\Payroll\Domain\Exception\PaycheckAdjustmentNotFoundException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollMonthInTheFutureException;
use App\Payroll\Domain\Exception\PayrollRunEmptyException;
use App\Payroll\Domain\Exception\PayrollRunNotApprovableException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
use App\Payroll\Domain\Model\Paycheck;
use App\Payroll\Domain\Model\PaycheckAdjustment;
use App\Payroll\Domain\Model\PaycheckLine;
use App\Payroll\Domain\Model\PayrollAccess;
use App\Payroll\Domain\Model\PayrollRun;
use App\Payroll\Domain\Model\PayrollRunStatus;
use App\Payroll\Domain\Model\PayrollSources;
use App\Payroll\Domain\Model\Rounding;
use App\Payroll\Domain\Model\ShiftLine;
use App\Payroll\Domain\Model\ShiftRate;
use App\Payroll\Domain\Model\ShiftRateKind;
use App\Payroll\Domain\Model\WorkedShift;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * October 2026 in an organization paying in PLN; "today" is 20 November unless a test says otherwise.
 * Bob and Eve work; the owner and the manager act.
 */
#[CoversClass(PayrollRun::class)]
#[CoversClass(Paycheck::class)]
#[CoversClass(PaycheckLine::class)]
#[CoversClass(PaycheckAdjustment::class)]
#[CoversClass(PayrollSources::class)]
#[CoversClass(ShiftLine::class)]
#[CoversClass(PayrollAccess::class)]
final class PayrollRunTest extends TestCase
{
    private const string TODAY = '2026-11-20';

    private Uuid $owner;
    private Uuid $manager;
    private Uuid $bob;
    private Uuid $eve;
    private Uuid $bobsRate;
    private Uuid $evesRate;
    /** @var list<WorkedShift> */
    private array $shifts = [];
    /** @var list<ShiftRate> */
    private array $rates = [];
    private int $pending = 0;
    private string $currency = 'PLN';

    protected function setUp(): void
    {
        $this->owner = Uuid::v7();
        $this->manager = Uuid::v7();
        $this->bob = Uuid::v7();
        $this->eve = Uuid::v7();
        $this->bobsRate = Uuid::v7();
        $this->evesRate = Uuid::v7();
    }

    public function test_it_pays_each_shift_at_its_dates_rate_and_rounds_the_paycheck_once(): void
    {
        // 33.33 an hour for two shifts of 1h 01m: each line is 33.8855 → 33.89, but the paycheck is 67.771 → 67.77, not 67.78.
        $first = $this->shift($this->bob, '2026-10-03', 3_660);
        $second = $this->shift($this->bob, '2026-10-04', 3_660);
        $this->hourlyFor($this->bob, '2026-10-01', '2026-10-31', 3_333);

        $run = $this->generate();

        self::assertSame(PayrollRunStatus::Draft, $run->getStatus());
        self::assertSame('PLN', $run->getCurrency());
        self::assertTrue(BusinessPeriod::month(2026, 10)->equals($run->month()));
        $paycheck = $this->paycheckOf($run, $this->bob);
        $lines = $paycheck->shiftLines();
        self::assertSame([$first->toRfc4122(), $second->toRfc4122()], array_map(static fn (ShiftLine $line): string => $line->shiftId->toRfc4122(), $lines));
        self::assertSame([3_389, 3_389], array_map(static fn (ShiftLine $line): ?int => $line->amount(), $lines));
        self::assertSame(ShiftRateKind::Hourly, $lines[0]->rateKind);
        self::assertTrue($this->bobsRate->equals($lines[0]->rateId ?? Uuid::v7()));
        self::assertSame(7_320, $paycheck->paidSeconds());
        self::assertSame(6_777, $paycheck->payForHours());
        self::assertSame(6_777, $paycheck->total());
        self::assertSame(6_777, $run->total());
    }

    public function test_it_pays_the_rate_in_effect_on_each_date_when_the_rate_changes_mid_month(): void
    {
        $this->shift($this->bob, '2026-10-14', 3_600);
        $this->shift($this->bob, '2026-10-15', 3_600);
        $this->hourlyFor($this->bob, '2026-10-01', '2026-10-14', 3_000);
        $raise = Uuid::v7();
        $this->hourlyFor($this->bob, '2026-10-15', '2026-10-31', 3_500, $raise);

        $lines = $this->paycheckOf($this->generate(), $this->bob)->shiftLines();

        self::assertSame([3_000, 3_500], array_map(static fn (ShiftLine $line): ?int => $line->rate, $lines));
        self::assertTrue($raise->equals($lines[1]->rateId ?? Uuid::v7()));
        self::assertSame(6_500, $this->paycheckOf($this->generate(), $this->bob)->payForHours());
    }

    public function test_it_pays_a_night_that_starts_on_the_months_last_day_wholly_at_that_days_rate(): void
    {
        // 31 October 20:00 → 1 November 03:00: Time tracking reports it under 31 October, with its full length.
        $this->shift($this->bob, '2026-10-31', 25_200);
        $this->hourlyFor($this->bob, '2026-10-01', '2026-10-31', 3_000);
        $this->rates[] = new ShiftRate($this->bob, BusinessDate::fromString('2026-10-31'), ShiftRateKind::Day, Uuid::v7(), 6_000, 'PLN');

        $line = $this->paycheckOf($this->generate(), $this->bob)->shiftLines()[0];

        self::assertSame(ShiftRateKind::Day, $line->rateKind);
        self::assertSame(25_200, $line->paidSeconds);
        self::assertSame(42_000, $line->amount());
    }

    public function test_it_lists_a_shift_without_a_rate_with_no_amount(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $this->hourlyFor($this->bob, '2026-10-01', '2026-10-31', 3_000);
        $this->shift($this->eve, '2026-10-03', 7_200);

        $run = $this->generate();

        $eves = $this->paycheckOf($run, $this->eve);
        $line = $eves->shiftLines()[0];
        self::assertNull($line->rate);
        self::assertNull($line->rateKind);
        self::assertNull($line->amount());
        self::assertSame(0, $eves->payForHours());
        self::assertSame(1, $eves->linesWithoutRate());
        self::assertSame(1, $run->linesWithoutRate());
    }

    public function test_it_counts_a_rate_in_another_currency_as_none(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $this->rates[] = new ShiftRate($this->bob, BusinessDate::fromString('2026-10-03'), ShiftRateKind::Hourly, Uuid::v7(), 3_000, 'EUR');

        self::assertSame(1, $this->generate()->linesWithoutRate());
    }

    public function test_it_rounds_each_shifts_length(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600 + 7 * 60);
        $this->shift($this->bob, '2026-10-04', 3_600 + 8 * 60);
        $this->hourlyFor($this->bob, '2026-10-01', '2026-10-31', 4_000);

        $paycheck = $this->paycheckOf($this->generate(Rounding::fromStrings('15', 'nearest')), $this->bob);

        self::assertSame([3_600, 4_500], array_map(static fn (ShiftLine $line): int => $line->paidSeconds, $paycheck->shiftLines()));
        self::assertSame(9_000, $paycheck->payForHours());
    }

    public function test_it_leaves_open_shifts_off_the_paychecks_and_counts_them(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $this->shift($this->bob, '2026-10-31', null);
        $this->pending = 2;

        $run = $this->generate();

        self::assertCount(1, $this->paycheckOf($run, $this->bob)->shiftLines());
        self::assertSame(1, $run->getOpenShifts());
        self::assertSame(2, $run->getPendingChangeRequests());
    }

    public function test_it_refuses_a_month_with_nothing_to_pay(): void
    {
        $this->shift($this->bob, '2026-10-31', null);

        $this->expectException(PayrollRunEmptyException::class);

        $this->generate();
    }

    public function test_it_generates_the_current_month_as_a_preview_but_not_a_later_one(): void
    {
        $this->shift($this->bob, '2026-11-02', 3_600, 11);
        self::assertTrue(BusinessPeriod::month(2026, 11)->equals($this->generate(month: BusinessPeriod::month(2026, 11))->month()));

        $this->shift($this->bob, '2026-12-01', 3_600, 12);
        $this->expectException(PayrollMonthInTheFutureException::class);

        $this->generate(month: BusinessPeriod::month(2026, 12));
    }

    public function test_it_lets_only_owners_and_managers_generate(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        self::assertCount(1, $this->generate(actorRole: MembershipRole::Manager)->paychecks());

        $this->expectException(PayrollAccessForbiddenException::class);

        $this->generate(actorRole: MembershipRole::Employee);
    }

    public function test_it_regenerates_with_new_shifts_rates_and_rounding_and_keeps_the_adjustments(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_700);
        $evesShift = $this->shift($this->eve, '2026-10-03', 3_600);
        $run = $this->generate();
        $bonus = Uuid::v7();
        $run->addAdjustment($bonus, $this->bob, 'Bonus', 5_000, $this->manager, MembershipRole::Manager, $this->now());
        $evesPaycheck = $this->paycheckOf($run, $this->eve)->getId();

        // A rate for Bob arrives, Eve's shift is deleted and Carl's added.
        $this->hourlyFor($this->bob, '2026-10-01', '2026-10-31', 3_600);
        $this->shifts = array_values(array_filter($this->shifts, static fn (WorkedShift $shift): bool => !$shift->shiftId->equals($evesShift)));
        $carl = Uuid::v7();
        $this->shift($carl, '2026-10-05', 1_800);
        $run->regenerate(Rounding::fromStrings('60', 'up'), $this->sources(), $this->manager, MembershipRole::Manager, $this->now('+1 hour'));

        self::assertSame('60', $run->rounding()->unit->value);
        $bobs = $this->paycheckOf($run, $this->bob);
        self::assertSame(7_200, $bobs->shiftLines()[0]->paidSeconds);
        self::assertSame(7_200 + 5_000, $bobs->total());
        self::assertSame(['Bonus'], array_map(static fn (PaycheckAdjustment $adjustment): string => $adjustment->getLabel(), $bobs->adjustments()));
        self::assertNull($run->findPaycheck($this->eve), 'Eve has nothing left to be paid');
        self::assertNotNull($run->findPaycheck($carl));
        self::assertFalse($this->paycheckOf($run, $this->bob)->getId()->equals($evesPaycheck));
        self::assertEquals($this->now('+1 hour'), $run->getGeneratedAt());

        // Without new rounding, the run keeps its own.
        $run->regenerate(null, $this->sources(), $this->manager, MembershipRole::Manager, $this->now());
        self::assertSame('60', $run->rounding()->unit->value);
    }

    public function test_it_keeps_a_paycheck_that_holds_only_adjustments(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $run = $this->generate();
        $run->addAdjustment(Uuid::v7(), $this->eve, 'Training day', 20_000, $this->manager, MembershipRole::Manager, $this->now());

        $run->regenerate(null, $this->sources(), $this->manager, MembershipRole::Manager, $this->now());

        $eves = $this->paycheckOf($run, $this->eve);
        self::assertSame([], $eves->shiftLines());
        self::assertSame(20_000, $eves->total());

        // With Bob's shift gone too, Eve's adjustment still leaves something to pay.
        $this->shifts = [];
        $run->regenerate(null, $this->sources(), $this->manager, MembershipRole::Manager, $this->now());
        self::assertCount(1, $run->paychecks());
    }

    public function test_it_refuses_to_regenerate_into_nothing_to_pay(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $run = $this->generate();
        $this->shifts = [];

        $this->expectException(PayrollRunEmptyException::class);

        $run->regenerate(null, $this->sources(), $this->manager, MembershipRole::Manager, $this->now());
    }

    public function test_it_changes_and_removes_adjustments_and_drops_a_paycheck_left_empty(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $run = $this->generate();
        $advance = Uuid::v7();
        $run->addAdjustment($advance, $this->eve, 'Advance', -10_000, $this->owner, MembershipRole::Owner, $this->now());

        $run->changeAdjustment($advance, '  Advance paid 10 October ', -12_000, $this->owner, MembershipRole::Owner, $this->now('+1 hour'));
        $adjustment = $this->paycheckOf($run, $this->eve)->adjustments()[0];
        self::assertSame('Advance paid 10 October', $adjustment->getLabel());
        self::assertSame(-12_000, $adjustment->getAmount());
        self::assertEquals($this->now('+1 hour'), $adjustment->getUpdatedAt());

        $run->removeAdjustment($advance, $this->owner, MembershipRole::Owner);
        self::assertNull($run->findPaycheck($this->eve));
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidAdjustments(): iterable
    {
        yield 'blank label' => ['  ', 100];
        yield 'long label' => [str_repeat('a', 101), 100];
        yield 'zero' => ['Bonus', 0];
        yield 'too large' => ['Bonus', PHP_INT_MAX];
    }

    #[DataProvider('invalidAdjustments')]
    public function test_it_refuses_an_invalid_adjustment(string $label, int $amount): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $run = $this->generate();

        $this->expectException(InvalidPaycheckAdjustmentException::class);

        $run->addAdjustment(Uuid::v7(), $this->bob, $label, $amount, $this->owner, MembershipRole::Owner, $this->now());
    }

    public function test_it_refuses_an_adjustment_that_is_not_on_the_run(): void
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $run = $this->generate();

        $this->expectException(PaycheckAdjustmentNotFoundException::class);

        $run->removeAdjustment(Uuid::v7(), $this->owner, MembershipRole::Owner);
    }

    public function test_it_is_approved_by_an_owner(): void
    {
        $run = $this->approvableRun();

        $run->approve($this->sources(), $this->today(), $this->owner, MembershipRole::Owner, $this->now());

        self::assertSame(PayrollRunStatus::Approved, $run->getStatus());
        self::assertTrue($this->owner->equals($run->getApprovedBy() ?? Uuid::v7()));
        self::assertEquals($this->now(), $run->getApprovedAt());
    }

    public function test_it_is_not_approved_by_a_manager(): void
    {
        $run = $this->approvableRun();

        $this->expectException(PayrollAccessForbiddenException::class);

        $run->approve($this->sources(), $this->today(), $this->manager, MembershipRole::Manager, $this->now());
    }

    public function test_it_is_not_approved_twice(): void
    {
        $run = $this->approvableRun();
        $run->approve($this->sources(), $this->today(), $this->owner, MembershipRole::Owner, $this->now());

        $this->expectException(PayrollRunStatusException::class);

        $run->approve($this->sources(), $this->today(), $this->owner, MembershipRole::Owner, $this->now());
    }

    public function test_it_is_not_approved_before_the_month_ends(): void
    {
        $run = $this->approvableRun();

        $this->expectException(PayrollRunNotApprovableException::class);
        $this->expectExceptionMessage('has not ended');

        $run->approve($this->sources(), BusinessDate::fromString('2026-10-31'), $this->owner, MembershipRole::Owner, $this->now());
    }

    /** @return iterable<string, array{\Closure(self, PayrollRun): void, string}> */
    public static function blockers(): iterable
    {
        yield 'an open shift' => [static function (self $test): void {
            $test->shift($test->bob, '2026-10-30', null);
        }, 'still open'];
        yield 'a pending change request' => [static function (self $test): void {
            $test->pending = 1;
        }, 'pending'];
        yield 'a line without a rate' => [static function (self $test, PayrollRun $run): void {
            $test->shift($test->eve, '2026-10-05', 3_600);
            $run->regenerate(null, $test->sources(), $test->owner, MembershipRole::Owner, $test->now());
        }, 'have no rate'];
        yield 'a negative total' => [static function (self $test, PayrollRun $run): void {
            $run->addAdjustment(Uuid::v7(), $test->bob, 'Advance', -1_000_000, $test->owner, MembershipRole::Owner, $test->now());
        }, 'negative total'];
        yield 'a new shift' => [static function (self $test): void {
            $test->shift($test->bob, '2026-10-20', 3_600);
        }, 'regenerate it'];
        yield 'a changed rate' => [static function (self $test): void {
            $test->rates = [];
            $test->hourlyFor($test->bob, '2026-10-01', '2026-10-31', 3_100);
        }, 'regenerate it'];
        yield 'a corrected shift' => [static function (self $test): void {
            $test->shifts = [new WorkedShift($test->shifts[0]->shiftId, $test->bob, $test->shifts[0]->date, 4_000)];
        }, 'regenerate it'];
        yield 'another currency' => [static function (self $test): void {
            $test->currency = 'EUR';
        }, 'regenerate it'];
    }

    /** @param \Closure(self, PayrollRun): void $change */
    #[DataProvider('blockers')]
    public function test_it_names_why_it_cannot_be_approved(\Closure $change, string $reason): void
    {
        $run = $this->approvableRun();
        $change($this, $run);

        $this->expectException(PayrollRunNotApprovableException::class);
        $this->expectExceptionMessage($reason);

        $run->approve($this->sources(), $this->today(), $this->owner, MembershipRole::Owner, $this->now());
    }

    public function test_it_is_not_approved_without_paychecks(): void
    {
        $run = $this->approvableRun();
        $this->shifts = [];
        $run->addAdjustment($adjustment = Uuid::v7(), $this->eve, 'Bonus', 100, $this->owner, MembershipRole::Owner, $this->now());
        $run->regenerate(null, $this->sources(), $this->owner, MembershipRole::Owner, $this->now());
        $run->removeAdjustment($adjustment, $this->owner, MembershipRole::Owner);

        $this->expectException(PayrollRunNotApprovableException::class);
        $this->expectExceptionMessage('no paychecks');

        $run->approve($this->sources(), $this->today(), $this->owner, MembershipRole::Owner, $this->now());
    }

    public function test_an_approved_run_is_not_changed_until_an_owner_reopens_it(): void
    {
        $run = $this->approvableRun();
        $run->approve($this->sources(), $this->today(), $this->owner, MembershipRole::Owner, $this->now());

        foreach ([
            fn () => $run->regenerate(null, $this->sources(), $this->owner, MembershipRole::Owner, $this->now()),
            fn () => $run->delete($this->owner, MembershipRole::Owner),
            fn () => $run->addAdjustment(Uuid::v7(), $this->bob, 'Bonus', 100, $this->owner, MembershipRole::Owner, $this->now()),
        ] as $change) {
            try {
                $change();
                self::fail('An approved run must not change.');
            } catch (PayrollRunStatusException) {
            }
        }

        try {
            $run->reopen($this->manager, MembershipRole::Manager);
            self::fail('Only owners reopen.');
        } catch (PayrollAccessForbiddenException) {
        }

        $run->reopen($this->owner, MembershipRole::Owner);
        self::assertSame(PayrollRunStatus::Draft, $run->getStatus());
        self::assertNull($run->getApprovedBy());
        self::assertNull($run->getApprovedAt());
        $run->delete($this->manager, MembershipRole::Manager);

        $this->expectException(PayrollRunStatusException::class);
        $run->reopen($this->owner, MembershipRole::Owner);
    }

    private function approvableRun(): PayrollRun
    {
        $this->shift($this->bob, '2026-10-03', 3_600);
        $this->hourlyFor($this->bob, '2026-10-01', '2026-10-31', 3_000);

        return $this->generate();
    }

    private function generate(?Rounding $rounding = null, ?BusinessPeriod $month = null, MembershipRole $actorRole = MembershipRole::Owner): PayrollRun
    {
        return PayrollRun::generate(Uuid::v7(), $rounding ?? Rounding::exact(), $this->sources($month), $this->today(), $this->owner, $actorRole, $this->now());
    }

    private function sources(?BusinessPeriod $month = null): PayrollSources
    {
        $month ??= BusinessPeriod::month(2026, 10);

        return new PayrollSources(
            $month,
            array_values(array_filter($this->shifts, static fn (WorkedShift $shift): bool => $month->contains($shift->date))),
            $this->rates,
            $this->pending,
            $this->currency,
        );
    }

    private function shift(Uuid $userId, string $date, ?int $seconds, int $month = 10): Uuid
    {
        \assert((int) substr($date, 5, 2) === $month);
        $id = Uuid::v7();
        $this->shifts[] = new WorkedShift($id, $userId, BusinessDate::fromString($date), $seconds);

        return $id;
    }

    private function hourlyFor(Uuid $userId, string $from, string $to, int $amount, ?Uuid $rateId = null): void
    {
        $rateId ??= $userId->equals($this->bob) ? $this->bobsRate : $this->evesRate;
        $period = BusinessPeriod::fromStrings($from, $to);
        for ($date = $period->first; !$date->isAfter($period->last); $date = $date->next()) {
            $this->rates[] = new ShiftRate($userId, $date, ShiftRateKind::Hourly, $rateId, $amount, 'PLN');
        }
    }

    private function paycheckOf(PayrollRun $run, Uuid $userId): Paycheck
    {
        return $run->findPaycheck($userId) ?? throw new \LogicException('Paycheck expected.');
    }

    private function today(): BusinessDate
    {
        return BusinessDate::fromString(self::TODAY);
    }

    private function now(string $modifier = '+0 seconds'): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 10:00:00')->modify($modifier);
    }
}
