<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Shared\Domain\Time\BusinessPeriod;

/** A run is generated for a past month, or for the current one as a preview; not for a later one. */
final class PayrollMonthInTheFutureException extends PayrollException
{
    public static function month(BusinessPeriod $month): self
    {
        return new self(\sprintf('The month %s has not started yet.', substr($month->first->toString(), 0, 7)), ['month' => substr($month->first->toString(), 0, 7)]);
    }
}
