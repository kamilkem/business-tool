<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/** The paycheck is not on the payroll run. */
final class PaycheckNotFoundException extends PayrollException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('Paycheck "%s" not found.', $id), ['paycheckId' => $id]);
    }
}
