<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\RecordCashCount;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager counts a deposit storage outside a close (ADR 0033 §4); the count replaces its
 * balance. An active denomination not listed counts as zero.
 */
final readonly class RecordCashCountCommand implements CommandInterface
{
    /**
     * @param list<array{denominationId: string, pieces: int}> $lines      absolute pieces
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
