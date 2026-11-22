<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Repository;

use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\LedgerBalance;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\Shared\Domain\Time\UtcRange;
use Symfony\Component\Uid\Uuid;

/**
 * Cash movements of the current organization only (TenantFilter), and the ledger queries over them
 * (ADR 0033 §4). Ledger order is (occurredAt, id).
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

    /**
     * The storage's balance just before the position (null: after everything): its last count
     * before it, plus every signed line after that count and before the position.
     */
    public function balanceOf(Uuid $storageId, ?LedgerPosition $before = null): LedgerBalance;

    /**
     * What every record after the position does to the storage, in ledger order.
     *
     * @return list<LedgerEntry>
     */
    public function entriesAfter(Uuid $storageId, LedgerPosition $after): array;

    public function add(CashMovement $movement): void;

    public function remove(CashMovement $movement): void;
}
