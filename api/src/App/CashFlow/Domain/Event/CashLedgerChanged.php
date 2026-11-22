<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * The cash ledger changed, and that is saved (ADR 0033 §7): a cash movement was recorded or deleted,
 * or an end-of-day close was corrected or deleted. The first close after the record's position
 * (`occurredAt`, `recordId`) that counted each of the storages has a different expected opening now;
 * RecalculateDayClosesOnCashLedgerChanged sends one RecalculateDayCloseCommand for each such close.
 * Later closes are anchored by that close's own count.
 */
final readonly class CashLedgerChanged implements EventInterface
{
    /** @param list<string> $storageIds the storages whose ledger changed */
    public function __construct(
        public string $organizationId,
        /** The movement or the close whose position the change is at. */
        public string $recordId,
        public array $storageIds,
        /** ISO 8601: the moment of the record in the ledger, from which the storages' balances changed. */
        public string $occurredAt,
        /** The owner or manager who recorded, corrected or deleted the record. */
        public string $actorId,
    ) {
    }
}
