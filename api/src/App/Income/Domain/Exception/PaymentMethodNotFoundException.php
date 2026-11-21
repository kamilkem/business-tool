<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** No such payment method in this organization (another organization's is not found either). */
final class PaymentMethodNotFoundException extends IncomeException
{
    public static function withId(string $paymentMethodId): self
    {
        return new self(\sprintf('Payment method "%s" not found.', $paymentMethodId), ['paymentMethodId' => $paymentMethodId]);
    }
}
