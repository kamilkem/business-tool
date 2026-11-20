<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;

/**
 * The organization's locked periods (approved payroll months, owned by Time tracking: ADR 0027 §8),
 * as the rate writes check them.
 *
 * ADR 0027 §3 refuses a rate "for a date inside a locked period". An hourly rate is in effect from its
 * date until the person's next rate, so changing one whose own date is open can still change the rate
 * of a locked date after it; #60 refuses that too: a change is checked against every date it affects
 * (HourlyRateHistory::affectedFrom()).
 */
final readonly class LockedPayrollDates
{
    /** @param list<BusinessPeriod> $periods */
    private function __construct(
        private array $periods,
    ) {
    }

    /** @param list<BusinessPeriod> $periods */
    public static function of(array $periods): self
    {
        return new self($periods);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param ?BusinessDate $last inclusive; null for every date from $first on
     *
     * @throws PayRatePeriodLockedException when a date from $first to $last is locked
     */
    public function assertUnlocked(BusinessDate $first, ?BusinessDate $last): void
    {
        foreach ($this->periods as $period) {
            $endsBefore = $period->last->isBefore($first);
            $startsAfter = null !== $last && $period->first->isAfter($last);
            if (!$endsBefore && !$startsAfter) {
                throw PayRatePeriodLockedException::affecting($first, $last, $period);
            }
        }
    }
}
