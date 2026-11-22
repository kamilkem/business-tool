<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\RecordCashAdjustment;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager adds or takes out pieces on one storage (ADR 0033 §4): change from the bank,
 * a bank run, an opening float.
 */
final readonly class RecordCashAdjustmentCommand implements CommandInterface
{
    /**
     * @param list<array{denominationId: string, pieces: int}> $lines      signed pieces, never zero
     * @param ?string                                          $occurredAt ISO 8601 with an offset; null for now
     */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $movementId,
        public string $storageId,
        public array $lines,
        public ?string $note = null,
        public ?string $occurredAt = null,
    ) {
    }
}
