<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;

/**
 * The business date an end-of-day close at a moment belongs to, by the day-end time (ADR 0033 §5).
 *
 * On the organization's local wall clock: the date the moment falls on there, or the date before it
 * while the local time is before the day-end time. A DST change therefore does not move the
 * boundary: with 05:00, 04:59 local is the previous date and 05:00 is the same date, whatever the
 * offset.
 */
final class DayCloseDate
{
    public static function for(\DateTimeImmutable $now, \DateTimeZone $timeZone, DayEndTime $dayEndsAt): BusinessDate
    {
        $date = BusinessDate::containing($now, $timeZone);

        return $dayEndsAt->isAfterTheLocalTimeOf($now, $timeZone) ? $date->previous() : $date;
    }
}
