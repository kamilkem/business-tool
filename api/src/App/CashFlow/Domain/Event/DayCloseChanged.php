<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * What Income books from an end-of-day close changed, and that is saved (ADR 0033 §7, §9): an owner
 * or manager corrected its register, non-cash amounts or counts, or the ledger before it changed and
 * its cash income with it. Income books it again into the register's revenue entry for the date
 * (the upsert of DayCloseRecorded; another register removes the old entry), as the actor.
 */
final readonly class DayCloseChanged implements EventInterface
{
    /** @param array<string, int> $nonCashAmounts minor units by payment method id */
    public function __construct(
        public string $organizationId,
        public string $dayCloseId,
        public string $registerId,
        /** Y-m-d: the business date closed. */
        public string $date,
        /** ISO 4217. */
        public string $currency,
        /** Minor units; negative for a shortage. */
        public int $cashIncome,
        public array $nonCashAmounts,
        /** The owner or manager who corrected the close, or who changed the ledger before it. */
        public string $actorId,
    ) {
    }
}
