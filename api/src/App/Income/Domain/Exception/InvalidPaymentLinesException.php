<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** An entry has at least one payment line, each method at most once, with a non-negative amount (ADR 0022 §4). */
final class InvalidPaymentLinesException extends IncomeException
{
    public static function empty(): self
    {
        return new self('An entry needs at least one payment line (an amount of zero is allowed).');
    }

    public static function unknownMethod(string $method): self
    {
        return new self(\sprintf('"%s" is not a payment method.', $method));
    }

    public static function duplicateMethod(string $method): self
    {
        return new self(\sprintf('The payment method "%s" appears more than once.', $method));
    }

    public static function amountOutOfRange(string $method, int $amount, int $max): self
    {
        return new self(\sprintf('The amount for "%s" must be between 0 and %d minor units, %d given.', $method, $max, $amount));
    }
}
