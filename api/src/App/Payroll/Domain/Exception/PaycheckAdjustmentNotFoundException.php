<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/** The adjustment is not on the payroll run. */
final class PaycheckAdjustmentNotFoundException extends PayrollException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('Adjustment "%s" not found.', $id), ['adjustmentId' => $id]);
    }
}
