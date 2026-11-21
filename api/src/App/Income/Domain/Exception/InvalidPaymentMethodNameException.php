<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

final class InvalidPaymentMethodNameException extends IncomeException
{
    public static function of(string $name, int $maxLength): self
    {
        return new self(\sprintf('A payment method name must have 1 to %d characters, "%s" given.', $maxLength, $name), ['name' => $name, 'maxLength' => $maxLength]);
    }
}
