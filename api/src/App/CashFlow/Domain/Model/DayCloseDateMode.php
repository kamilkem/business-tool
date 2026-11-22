<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/** How an employee's end-of-day close gets its business date (ADR 0033 §5). */
enum DayCloseDateMode: string
{
    /** The date follows from the day-end time; the employee does not choose (the default). */
    case DayEndTime = 'day_end_time';

    /** The employee picks today or yesterday; the time clock preselects the day-end time's date. */
    case EmployeePicks = 'employee_picks';
}
