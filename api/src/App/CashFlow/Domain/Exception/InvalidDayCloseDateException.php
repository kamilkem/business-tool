<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * The date picked for an end-of-day close is not one the organization's setting allows (ADR 0033
 * §5, DayCloseRule::dateOfClose()). Dates are Y-m-d in the organization's time zone.
 */
final class InvalidDayCloseDateException extends CashFlowException
{
    /** Owners and managers pick any date up to today. */
    public static function inTheFuture(string $date, string $today): self
    {
        return new self(\sprintf('A day can be closed up to today (%s), not on %s.', $today, $date), ['date' => $date, 'today' => $today], variant: __FUNCTION__);
    }

    /** In `day_end_time` mode, an employee's close gets the date of the day-end time. */
    public static function notChosenByEmployee(string $date, string $dayCloseDate): self
    {
        return new self(\sprintf('The close\'s date follows from the day-end time: it is %s, not %s.', $dayCloseDate, $date), ['date' => $date, 'dayCloseDate' => $dayCloseDate], variant: __FUNCTION__);
    }

    /** In `employee_picks` mode, an employee picks today or yesterday. */
    public static function notTodayOrYesterday(string $date, string $today): self
    {
        return new self(\sprintf('An employee closes today (%s) or yesterday, not %s.', $today, $date), ['date' => $date, 'today' => $today], variant: __FUNCTION__);
    }
}
