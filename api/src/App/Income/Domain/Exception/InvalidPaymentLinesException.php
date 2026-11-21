<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/**
 * An entry has at least one payment line, each of the organization's methods at most once, with a
 * non-negative amount (ADR 0022 §4); a deactivated method takes no new lines (ADR 0030 §4).
 */
final class InvalidPaymentLinesException extends IncomeException
{
    public static function empty(): self
    {
        return new self('An entry needs at least one payment line (an amount of zero is allowed).', [], variant: __FUNCTION__);
    }

    public static function unknownMethod(string $paymentMethodId): self
    {
        return new self(\sprintf('"%s" is not one of the organization\'s payment methods.', $paymentMethodId), ['paymentMethodId' => $paymentMethodId], variant: __FUNCTION__);
    }

    public static function duplicateMethod(string $paymentMethodId, string $name): self
    {
        return new self(\sprintf('The payment method "%s" appears more than once.', $name), ['paymentMethodId' => $paymentMethodId, 'name' => $name], variant: __FUNCTION__);
    }

    public static function methodDeactivated(string $paymentMethodId, string $name): self
    {
        return new self(\sprintf('The payment method "%s" is deactivated and takes no new lines.', $name), ['paymentMethodId' => $paymentMethodId, 'name' => $name], variant: __FUNCTION__);
    }

    public static function amountOutOfRange(string $paymentMethodId, string $name, int $amount, int $max): self
    {
        return new self(\sprintf('The amount for "%s" must be between 0 and %d minor units, %d given.', $name, $max, $amount), ['paymentMethodId' => $paymentMethodId, 'name' => $name, 'amount' => $amount, 'max' => $max], variant: __FUNCTION__);
    }
}
