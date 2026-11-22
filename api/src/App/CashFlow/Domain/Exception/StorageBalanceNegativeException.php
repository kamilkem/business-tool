<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * A deposit storage never holds fewer than zero pieces of a denomination at any point of its ledger
 * (ADR 0033 §4): the write or deletion would take out pieces it does not hold at that moment.
 */
final class StorageBalanceNegativeException extends CashFlowException
{
    /**
     * @param int                $value  the denomination's face value in minor units
     * @param int                $pieces the balance the storage would have, below zero
     * @param \DateTimeImmutable $at     the moment of the ledger at which it would go below zero
     */
    public static function of(string $storageId, string $name, string $denominationId, string $currency, int $value, int $pieces, \DateTimeImmutable $at): self
    {
        return new self(
            \sprintf('The cash storage "%s" would hold %d pieces of the denomination of %d minor units of %s at %s.', $name, $pieces, $value, $currency, $at->format(\DATE_ATOM)),
            [
                'storageId' => $storageId,
                'name' => $name,
                'denominationId' => $denominationId,
                'currency' => $currency,
                'value' => $value,
                'pieces' => $pieces,
                'at' => $at->format(\DATE_ATOM),
            ],
        );
    }
}
