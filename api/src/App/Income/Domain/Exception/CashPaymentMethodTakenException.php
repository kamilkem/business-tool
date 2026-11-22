<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/**
 * At most one active payment method of an organization is marked as cash: the end-of-day close
 * fills its line with the counted cash income (ADR 0033 §9). The parameters are the method that was
 * to be marked or reactivated; the admin's list shows which one is cash already.
 */
final class CashPaymentMethodTakenException extends IncomeException
{
    public static function of(string $paymentMethodId, string $name): self
    {
        return new self(
            \sprintf('Another active payment method is already marked as cash, so "%s" cannot be.', $name),
            ['paymentMethodId' => $paymentMethodId, 'name' => $name],
        );
    }
}
