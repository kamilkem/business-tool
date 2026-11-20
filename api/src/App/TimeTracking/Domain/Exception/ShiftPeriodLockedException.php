<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

use App\Shared\Domain\Time\BusinessDate;

/**
 * The shift's business date (the date it started on, in the organization's time zone) is in a locked
 * period, such as a month whose payroll run is approved (ADR 0027 §8): its shifts no longer change.
 */
final class ShiftPeriodLockedException extends TimeTrackingException
{
    public static function on(BusinessDate $date): self
    {
        return new self(\sprintf('The shifts of %s are locked: that period has been settled.', $date->toString()));
    }
}
