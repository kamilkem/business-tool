<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** End-of-day closes were asked for over more days than one request may cover. */
final class DayClosePeriodTooLongException extends CashFlowException
{
    public static function of(BusinessPeriod $period, int $maxDays): self
    {
        return new self(\sprintf(
            'Day closes are read for at most %d days at a time; %s to %s is %d days.',
            $maxDays,
            $period->first,
            $period->last,
            $period->dayCount(),
        ), ['from' => $period->first->toString(), 'to' => $period->last->toString(), 'days' => $period->dayCount(), 'maxDays' => $maxDays]);
    }
}
