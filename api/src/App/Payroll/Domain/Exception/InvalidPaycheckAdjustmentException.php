<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/** An adjustment has a label and a non-zero amount in minor units (ADR 0027 §6). */
final class InvalidPaycheckAdjustmentException extends PayrollException
{
    public static function label(int $maxLength): self
    {
        return new self(\sprintf('An adjustment needs a label of 1 to %d characters.', $maxLength), ['maxLength' => $maxLength], variant: __FUNCTION__);
    }

    public static function amount(int $amount, int $max): self
    {
        return new self(\sprintf('An adjustment is a non-zero amount from -%2$d to %2$d minor units, %1$d given.', $amount, $max), ['amount' => $amount, 'max' => $max], variant: __FUNCTION__);
    }
}
