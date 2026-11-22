<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** The organization already has a denomination of this value in this currency, active or deactivated (ADR 0033 §2). */
final class DenominationValueTakenException extends CashFlowException
{
    public static function of(string $currency, int $value): self
    {
        return new self(\sprintf('A denomination of %d minor units of %s already exists.', $value, $currency), ['currency' => $currency, 'value' => $value]);
    }
}
