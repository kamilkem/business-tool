<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** An organization has one payroll run per month (ADR 0027 §4). */
final class PayrollRunMonthTakenException extends PayrollException
{
    public static function month(BusinessPeriod $month): self
    {
        return new self(\sprintf('There is already a payroll run for %s.', substr($month->first->toString(), 0, 7)), ['month' => substr($month->first->toString(), 0, 7)]);
    }
}
