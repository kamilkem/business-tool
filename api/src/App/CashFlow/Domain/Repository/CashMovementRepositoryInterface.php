<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Repository;

use App\CashFlow\Domain\Model\CashMovement;
use App\Shared\Domain\Time\UtcRange;
use Symfony\Component\Uid\Uuid;

/**
 * Cash movements of the current organization only (TenantFilter, ADR 0033 §4). Ledger order is
 * (occurredAt, id); the ledger over movements and closes is CashLedgerInterface.
 */
interface CashMovementRepositoryInterface
{
    public function findById(Uuid $id): ?CashMovement;

    /**
     * The movements at a moment in [from, to), newest first in ledger order.
     *
     * @param ?Uuid $storageId only the movements with a line on this storage
     *
     * @return list<CashMovement>
     */
    public function findOccurredIn(UtcRange $range, ?Uuid $storageId = null): array;

    public function add(CashMovement $movement): void;

    public function remove(CashMovement $movement): void;
}
