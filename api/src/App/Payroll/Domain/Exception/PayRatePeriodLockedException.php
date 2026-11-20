<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;

/**
 * The change would alter the rate of a date in a locked period (an approved payroll month,
 * ADR 0027 §3 and §8).
 */
final class PayRatePeriodLockedException extends PayrollException
{
    public static function affecting(BusinessDate $first, ?BusinessDate $last, BusinessPeriod $locked): self
    {
        return new self(\sprintf(
            'The change affects the rates from %s %s, which overlaps the locked period %s to %s.',
            $first,
            null === $last ? 'onwards' : 'to '.$last,
            $locked->first,
            $locked->last,
        ));
    }
}
