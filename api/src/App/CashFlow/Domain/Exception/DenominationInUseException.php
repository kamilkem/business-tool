<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** Only a denomination no close or movement uses can be deleted; a used one is deactivated (ADR 0033 §2). */
final class DenominationInUseException extends CashFlowException
{
    public static function of(string $denominationId, string $currency, int $value): self
    {
        return new self(\sprintf('The denomination of %d minor units of %s is used by cash records; deactivate it instead.', $value, $currency), ['denominationId' => $denominationId, 'currency' => $currency, 'value' => $value]);
    }
}
