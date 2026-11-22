<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** A face value is a positive number of minor units, at most an INTEGER column holds (ADR 0033 §2). */
final class InvalidDenominationValueException extends CashFlowException
{
    public static function of(int $value, int $max): self
    {
        return new self(\sprintf('A denomination is worth 1 to %d minor units, not %d.', $max, $value), ['value' => $value, 'max' => $max]);
    }
}
