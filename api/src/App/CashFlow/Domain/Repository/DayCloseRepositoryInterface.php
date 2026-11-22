<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Repository;

use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/** End-of-day closes of the current organization only (TenantFilter). */
interface DayCloseRepositoryInterface
{
    public function findById(Uuid $id): ?DayClose;

    public function findByDate(BusinessDate $date): ?DayClose;

    /** The close of the latest business date, if any. */
    public function findLatest(): ?DayClose;

    /** @return list<DayClose> the closes of the dates in the period, latest date first */
    public function findInPeriod(BusinessPeriod $period): array;

    /**
     * The first close after the position, in ledger order (closedAt, id), that counted the storage
     * (its DayCloseStorage's mode is counted): the one whose expected opening a change of the ledger
     * at the position changes (ADR 0033 §7).
     */
    public function findFirstCountingAfter(Uuid $storageId, LedgerPosition $after): ?DayClose;

    /** @throws DayAlreadyClosedException when another close of the date was saved at the same moment */
    public function add(DayClose $close): void;

    public function save(DayClose $close): void;

    public function remove(DayClose $close): void;
}
