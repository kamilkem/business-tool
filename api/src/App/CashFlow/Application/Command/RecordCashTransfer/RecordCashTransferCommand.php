<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\RecordCashTransfer;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager moves the same pieces out of one storage and into another (ADR 0033 §4). */
final readonly class RecordCashTransferCommand implements CommandInterface
{
    /**
     * @param list<array{denominationId: string, pieces: int}> $lines      positive pieces
     * @param ?string                                          $occurredAt ISO 8601 with an offset; null for now
     */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $movementId,
        public string $fromStorageId,
        public string $toStorageId,
        public array $lines,
        public ?string $note = null,
        public ?string $occurredAt = null,
    ) {
    }
}
