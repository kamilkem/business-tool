<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** Revenue entries or a summary were asked for over more days than one request may cover. */
final class RevenuePeriodTooLongException extends IncomeException
{
    public static function of(BusinessPeriod $period, int $maxDays): self
    {
        return new self(\sprintf(
            'Revenue is read for at most %d days at a time; %s to %s is %d days.',
            $maxDays,
            $period->first,
            $period->last,
            $period->dayCount(),
        ), ['from' => $period->first->toString(), 'to' => $period->last->toString(), 'days' => $period->dayCount(), 'maxDays' => $maxDays]);
    }
}
