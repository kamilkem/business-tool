<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** Payment method names are unique within an organization, deactivated methods included (ADR 0030 §1). */
final class PaymentMethodNameTakenException extends IncomeException
{
    public static function of(string $name): self
    {
        return new self(\sprintf('The organization already has a payment method named "%s".', $name), ['name' => $name]);
    }
}
