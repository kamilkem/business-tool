<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * An end-of-day close that breaks a rule of its shape (ADR 0033 §6): it covers every active storage
 * once, counts within the limits, gives one amount per active non-cash payment method and books into
 * an active register. The inputs check the same rules first where they can.
 */
final class InvalidDayCloseException extends CashFlowException
{
    /** No active storage, or no active denomination of the organization's currency: nothing to count. */
    public static function nothingToClose(string $currency): self
    {
        return new self(\sprintf('A day is closed only while at least one cash storage and one denomination in %s are active.', $currency), ['currency' => $currency], variant: __FUNCTION__);
    }

    /** Income has no active payment method to book the close into: none marked as cash, and no non-cash one (ADR 0033 §9). */
    public static function nothingToBook(): self
    {
        return new self('A day is closed only while at least one payment method is active: the one marked as cash, or a non-cash one.', [], variant: __FUNCTION__);
    }

    /** The cash income is booked as one revenue line, which takes at most $max minor units. */
    public static function cashIncome(int $cashIncome, int $max): self
    {
        return new self(
            \sprintf('A cash income of %d is more than a revenue entry takes (at most %d): check the counts.', $cashIncome, $max),
            ['cashIncome' => $cashIncome, 'max' => $max],
            variant: __FUNCTION__,
        );
    }

    public static function missingStorage(string $storageId, string $name): self
    {
        return new self(\sprintf('The close covers every active cash storage; "%s" is missing.', $name), ['storageId' => $storageId, 'name' => $name], variant: __FUNCTION__);
    }

    /** A correction covers the storages the close covered when it was made (ADR 0033 §7). */
    public static function storageNotInClose(string $storageId, string $name): self
    {
        return new self(\sprintf('The cash storage "%s" is not in this close: a correction covers the storages the close covered.', $name), ['storageId' => $storageId, 'name' => $name], variant: __FUNCTION__);
    }

    public static function duplicateStorage(string $storageId): self
    {
        return new self(\sprintf('The cash storage "%s" may appear only once in a close.', $storageId), ['storageId' => $storageId], variant: __FUNCTION__);
    }

    public static function duplicateDenomination(string $storageId, string $denominationId): self
    {
        return new self(\sprintf('The denomination "%s" may appear only once per storage in a close.', $denominationId), ['storageId' => $storageId, 'denominationId' => $denominationId], variant: __FUNCTION__);
    }

    public static function pieces(string $storageId, string $denominationId, int $pieces, int $max): self
    {
        return new self(
            \sprintf('%d pieces of the denomination "%s" is not allowed in a close (from 0 to %d).', $pieces, $denominationId, $max),
            ['storageId' => $storageId, 'denominationId' => $denominationId, 'pieces' => $pieces, 'min' => 0, 'max' => $max],
            variant: __FUNCTION__,
        );
    }

    /** The register is not an active register of the organization, so Income could not book the close. */
    public static function registerNotOffered(string $registerId): self
    {
        return new self(\sprintf('The register "%s" is not an active register of the organization.', $registerId), ['registerId' => $registerId], variant: __FUNCTION__);
    }

    public static function missingPaymentMethod(string $paymentMethodId, string $name): self
    {
        return new self(\sprintf('The close takes an amount for every active non-cash payment method; "%s" is missing.', $name), ['paymentMethodId' => $paymentMethodId, 'name' => $name], variant: __FUNCTION__);
    }

    /** Not an active non-cash payment method of the organization: unknown, deactivated, or the cash one. */
    public static function paymentMethodNotOffered(string $paymentMethodId): self
    {
        return new self(\sprintf('The payment method "%s" is not an active non-cash payment method of the organization.', $paymentMethodId), ['paymentMethodId' => $paymentMethodId], variant: __FUNCTION__);
    }

    /** A correction takes the non-cash payment methods the close took when it was made (ADR 0033 §7). */
    public static function paymentMethodNotInClose(string $paymentMethodId): self
    {
        return new self(\sprintf('The payment method "%s" is not in this close: a correction takes the non-cash payment methods the close took.', $paymentMethodId), ['paymentMethodId' => $paymentMethodId], variant: __FUNCTION__);
    }

    public static function duplicatePaymentMethod(string $paymentMethodId): self
    {
        return new self(\sprintf('The payment method "%s" may appear only once in a close.', $paymentMethodId), ['paymentMethodId' => $paymentMethodId], variant: __FUNCTION__);
    }

    public static function amount(string $paymentMethodId, int $amount, int $max): self
    {
        return new self(
            \sprintf('%d is not allowed as the amount of the payment method "%s" (from 0 to %d).', $amount, $paymentMethodId, $max),
            ['paymentMethodId' => $paymentMethodId, 'amount' => $amount, 'min' => 0, 'max' => $max],
            variant: __FUNCTION__,
        );
    }
}
