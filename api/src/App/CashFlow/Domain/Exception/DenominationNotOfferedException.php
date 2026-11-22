<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * Only the denominations of the organization's current currency are offered (ADR 0033 §2). A
 * deactivated one is only taken out of a storage, with an adjustment; it is not added, moved or
 * counted.
 */
final class DenominationNotOfferedException extends CashFlowException
{
    public static function otherCurrency(string $denominationId, string $currency, int $value, string $organizationCurrency): self
    {
        return new self(
            \sprintf('The denomination of %d minor units of %s is not in the organization\'s currency (%s).', $value, $currency, $organizationCurrency),
            ['denominationId' => $denominationId, 'currency' => $currency, 'value' => $value, 'organizationCurrency' => $organizationCurrency],
            variant: __FUNCTION__,
        );
    }

    public static function deactivated(string $denominationId, string $currency, int $value): self
    {
        return new self(
            \sprintf('The denomination of %d minor units of %s is deactivated: it can only be taken out of a storage, with an adjustment.', $value, $currency),
            ['denominationId' => $denominationId, 'currency' => $currency, 'value' => $value],
            variant: __FUNCTION__,
        );
    }
}
