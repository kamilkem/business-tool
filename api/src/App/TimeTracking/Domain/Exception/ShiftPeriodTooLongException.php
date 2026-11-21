<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** A list of shifts was asked for over more days than one request may cover. */
final class ShiftPeriodTooLongException extends TimeTrackingException
{
    public static function of(BusinessPeriod $period, int $maxDays): self
    {
        return new self(\sprintf(
            'Shifts are listed for at most %d days at a time; %s to %s is %d days.',
            $maxDays,
            $period->first,
            $period->last,
            $period->dayCount(),
        ), ['from' => $period->first->toString(), 'to' => $period->last->toString(), 'days' => $period->dayCount(), 'maxDays' => $maxDays]);
    }
}
