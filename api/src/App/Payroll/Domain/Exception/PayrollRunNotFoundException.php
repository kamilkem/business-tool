<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/** The payroll run does not exist in the current organization, or the actor may not see it. */
final class PayrollRunNotFoundException extends PayrollException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('Payroll run "%s" not found.', $id));
    }
}
