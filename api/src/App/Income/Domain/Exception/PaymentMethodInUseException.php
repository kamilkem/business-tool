<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** Only a payment method no revenue entry has used can be deleted; a used one is deactivated (ADR 0030 §4). */
final class PaymentMethodInUseException extends IncomeException
{
    public static function of(string $paymentMethodId, string $name): self
    {
        return new self(\sprintf('The payment method "%s" is used by revenue entries; deactivate it instead.', $name), ['paymentMethodId' => $paymentMethodId, 'name' => $name]);
    }
}
