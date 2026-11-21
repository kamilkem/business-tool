<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Shared\Domain\Time\BusinessDate;

/** A paid mark records a payment that happened: today or earlier in the organization's time zone (product owner, #63). */
final class PaymentDateInTheFutureException extends PayrollException
{
    public static function date(BusinessDate $paidOn, BusinessDate $today): self
    {
        return new self(\sprintf('The payment date %s is after today (%s).', $paidOn, $today), ['date' => $paidOn->toString(), 'today' => $today->toString()]);
    }
}
