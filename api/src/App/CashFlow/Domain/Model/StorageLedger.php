<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;

/**
 * The rules of one storage's ledger (ADR 0033 §4). Balances are derived, never stored: a storage
 * holds what its last count at or before a moment says, plus every signed entry after it, in ledger
 * order. A deposit storage never holds fewer than zero pieces of a denomination at any point; a
 * counted storage is not checked, because the day's takings are in it but not counted yet.
 *
 * The repository adapter computes the same balance in SQL for reads
 * (CashMovementRepositoryInterface::balanceOf()); the check below runs over the entries a write
 * affects.
 */
final class StorageLedger
{
    /**
     * @param array<string, int> $opening pieces by denomination id before the first entry
     * @param list<LedgerEntry>  $entries in ledger order
     *
     * @return array<string, int> pieces by denomination id after the last entry, without zeros
     */
    public static function balance(array $opening, array $entries): array
    {
        $balance = array_filter($opening, static fn (int $pieces): bool => 0 !== $pieces);
        foreach ($entries as $entry) {
            $balance = $entry->applyTo($balance);
        }

        return $balance;
    }

    /**
     * Checks that the storage never goes below zero pieces of a denomination over the entries, from
     * the opening on. A counted storage passes unchecked.
     *
     * @param array<string, int>          $opening       pieces by denomination id just before the first entry
     * @param list<LedgerEntry>           $entries       in ledger order: the changed part of the ledger and everything after it
     * @param array<string, Denomination> $denominations by id, every denomination the entries and the opening name
     *
     * @throws StorageBalanceNegativeException at the first entry after which a deposit storage holds fewer than zero pieces
     */
    public static function assertNeverNegative(CashStorage $storage, array $opening, array $entries, array $denominations): void
    {
        if (CashStorageMode::Deposit !== $storage->getMode()) {
            return;
        }

        $balance = $opening;
        foreach ($entries as $entry) {
            $balance = $entry->applyTo($balance);
            foreach ($balance as $denominationId => $pieces) {
                if ($pieces >= 0) {
                    continue;
                }

                $denomination = $denominations[$denominationId] ?? throw new \LogicException(\sprintf('Denomination "%s" expected.', $denominationId));

                throw StorageBalanceNegativeException::of(
                    $storage->getId()->toRfc4122(),
                    $storage->getName(),
                    (string) $denominationId,
                    $denomination->getCurrency(),
                    $denomination->getValue(),
                    $pieces,
                    $entry->position->occurredAt,
                );
            }
        }
    }
}
