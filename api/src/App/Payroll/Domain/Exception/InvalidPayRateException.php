<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/** A rate is a positive amount in minor units (ADR 0010) that fits an INTEGER column. */
final class InvalidPayRateException extends PayrollException
{
    public static function amount(int $amount, int $max): self
    {
        return new self(\sprintf('A pay rate must be from 1 to %d minor units, %d given.', $max, $amount), ['amount' => $amount, 'max' => $max]);
    }
}
