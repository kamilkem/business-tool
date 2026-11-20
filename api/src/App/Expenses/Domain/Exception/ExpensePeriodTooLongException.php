<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** A list of expenses covers at most a set number of days per request. */
final class ExpensePeriodTooLongException extends ExpensesException
{
    public static function of(BusinessPeriod $period, int $maxDays): self
    {
        return new self(\sprintf('The period has %d days; at most %d are allowed.', $period->dayCount(), $maxDays));
    }
}
