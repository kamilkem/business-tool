<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/** A storage's balance at a moment, derived from its ledger (StorageLedger), with the count it starts from. */
final readonly class LedgerBalance
{
    /** @param array<string, int> $pieces by denomination id, without zeros */
    public function __construct(
        public array $pieces,
        /** The moment of the last count the balance starts from; null when the storage was never counted. */
        public ?\DateTimeImmutable $countedAt,
    ) {
    }
}
