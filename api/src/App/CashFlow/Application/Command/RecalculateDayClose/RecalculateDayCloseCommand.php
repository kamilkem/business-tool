<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\RecalculateDayClose;

use App\Shared\Application\Command\CommandInterface;

/**
 * The ledger before an end-of-day close changed (ADR 0033 §7): recompute its expected openings and
 * cash income. Sent only by RecalculateDayClosesOnCashLedgerChanged, never by a Ui processor; the
 * actor is the owner or manager who changed the ledger, and Income books a changed close as them.
 */
final readonly class RecalculateDayCloseCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $dayCloseId,
    ) {
    }
}
