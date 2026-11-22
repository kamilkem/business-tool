<?php

declare(strict_types=1);

namespace App\CashFlow\Domain;

use App\CashFlow\Domain\Model\LedgerBalance;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use Symfony\Component\Uid\Uuid;

/**
 * The cash ledger of the current organization (TenantFilter, ADR 0033 §4, §6): the records of cash
 * movements and of end-of-day closes, in ledger order (moment, id). A count (an audit count, or a
 * close's count of a counted storage) sets a storage's pieces; every other record adds signed pieces
 * (a close's deposit adds them to a deposit storage).
 */
interface CashLedgerInterface
{
    /**
     * The storage's balance just before the position (null: after everything): its last count
     * before it, plus every signed record after that count and before the position.
     */
    public function balanceOf(Uuid $storageId, ?LedgerPosition $before = null): LedgerBalance;

    /**
     * What every record after the position does to the storage, in ledger order.
     *
     * @return list<LedgerEntry>
     */
    public function entriesAfter(Uuid $storageId, LedgerPosition $after): array;
}
