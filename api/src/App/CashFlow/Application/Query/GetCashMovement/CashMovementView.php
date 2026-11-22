<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetCashMovement;

final readonly class CashMovementView
{
    /** @param list<CashMovementLineView> $lines by value, lowest first */
    public function __construct(
        public string $id,
        /** `adjustment`, `transfer` or `count`. */
        public string $kind,
        public \DateTimeImmutable $occurredAt,
        /** The storage adjusted or counted, or the one a transfer takes cash out of. */
        public string $storageId,
        public string $storageName,
        /** The storage a transfer puts cash into; null for the other kinds. */
        public ?string $targetStorageId,
        public ?string $targetStorageName,
        /** ISO 4217: the currency of its denominations. */
        public string $currency,
        public array $lines,
        /** The sum of the lines' amounts: signed for an adjustment, moved for a transfer, counted for a count. */
        public int $total,
        /** A count's counted total minus the storage's balance just before it; null for the other kinds. */
        public ?int $discrepancy,
        public ?string $note,
        public string $createdById,
        public string $createdByFirstName,
        public string $createdByLastName,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
