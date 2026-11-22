<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * A cash movement was recorded or deleted, and that is saved (ADR 0033 §7). The first close after
 * `occurredAt` on each of the storages has a different expected opening now: recomputing it is the
 * handler that #189 adds (`RecalculateDayCloseCommand`, one per close). Until then nothing handles it.
 */
final readonly class CashLedgerChanged implements EventInterface
{
    /** @param list<string> $storageIds the storages whose ledger changed */
    public function __construct(
        public string $organizationId,
        public string $movementId,
        public array $storageIds,
        /** ISO 8601: the moment of the ledger from which the storages' balances changed. */
        public string $occurredAt,
        /** The owner or manager who recorded or deleted the movement. */
        public string $actorId,
    ) {
    }
}
