<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\CashLedgerInterface;
use App\CashFlow\Domain\Exception\IncomeMonthClosedException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Model\StorageLedger;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\Income\Application\Query\ListClosedRevenueMonths\ListClosedRevenueMonthsQuery;
use App\Income\Domain\Model\RevenueMonthLocks;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;

/**
 * Cash flow refuses up front what Income could not book in a closed month (ADR 0034 §3): a close
 * dated in it, and a ledger write that would change the cash income of a close in it through the
 * recalculation of ADR 0033 §7. Income's closed months come from its query, without an actor check.
 */
final readonly class IncomeMonthLock
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private DayCloseRepositoryInterface $closes,
        private CashLedgerInterface $ledger,
        private ExpectedOpenings $openings,
    ) {
    }

    /** @throws IncomeMonthClosedException when the date is in a closed Income month */
    public function assertOpen(BusinessDate $date): void
    {
        if ($this->locks()->isClosed($date)) {
            throw IncomeMonthClosedException::forDate($date->toString(), self::monthOf($date));
        }
    }

    /**
     * A write at $position (a movement or a close, recorded, corrected or deleted) that does
     * $entryFor($storage) to each of $storages (null: nothing, the record is deleted). For each
     * storage, the first close after it that counts the storage gets a new expected opening; if a
     * close in a closed month would end up with another cash income, the write is refused.
     *
     * @param list<CashStorage>                     $storages
     * @param \Closure(CashStorage): ?LedgerEntry $entryFor
     *
     * @throws IncomeMonthClosedException when a close in a closed Income month would change its cash income
     */
    public function assertNoClosedCloseChanges(LedgerPosition $position, array $storages, \Closure $entryFor): void
    {
        $locks = $this->locks();
        if (!$locks->any()) {
            return;
        }

        /** @var array<string, array{DayClose, int}> $changes the close and how much its expected openings drop, by close id */
        $changes = [];
        foreach ($storages as $storage) {
            $close = $this->closes->findFirstCountingAfter($storage->getId(), $position);
            if (null === $close || !$locks->isClosed($close->getBusinessDate())) {
                continue;
            }

            $expected = $this->expectedOpening($close, $storage);
            if (null === $expected) {
                continue;
            }

            $entries = array_values(array_filter(
                $this->ledger->entriesAfter($storage->getId(), $position),
                static fn (LedgerEntry $entry): bool => $entry->position->isBefore($close->position()),
            ));
            $entry = $entryFor($storage);
            if (null !== $entry) {
                array_unshift($entries, $entry);
            }
            $opening = $this->openings->valueOf(StorageLedger::balance($this->ledger->balanceOf($storage->getId(), $position)->pieces, $entries), $close->getCurrency());

            $id = $close->getId()->toRfc4122();
            $changes[$id] = [$close, ($changes[$id][1] ?? 0) + $expected - $opening];
        }

        foreach ($changes as [$close, $change]) {
            if (0 !== $change) {
                throw IncomeMonthClosedException::recalculatedClose($close->getId()->toRfc4122(), $close->getBusinessDate()->toString(), self::monthOf($close->getBusinessDate()));
            }
        }
    }

    private function locks(): RevenueMonthLocks
    {
        /** @var RevenueMonthLocks $locks */
        $locks = $this->queryBus->ask(new ListClosedRevenueMonthsQuery());

        return $locks;
    }

    private function expectedOpening(DayClose $close, CashStorage $storage): ?int
    {
        foreach ($close->getStorages() as $summary) {
            if ($summary->getStorage()->getId()->equals($storage->getId()) && CashStorageMode::Counted === $summary->getMode()) {
                return $summary->getExpectedOpening();
            }
        }

        return null;
    }

    private static function monthOf(BusinessDate $date): string
    {
        return substr($date->toString(), 0, 7);
    }
}
