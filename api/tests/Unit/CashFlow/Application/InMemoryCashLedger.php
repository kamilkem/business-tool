<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Domain\CashLedgerInterface;
use App\CashFlow\Domain\Model\LedgerBalance;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Model\StorageLedger;
use Symfony\Component\Uid\Uuid;

/**
 * Fake implementing the port over the in-memory movements and closes: no container, no database.
 * It follows the domain's rules (StorageLedger); CashLedgerTest checks the SQL ones.
 */
final readonly class InMemoryCashLedger implements CashLedgerInterface
{
    public function __construct(
        private InMemoryCashMovementRepository $movements,
        private InMemoryDayCloseRepository $closes = new InMemoryDayCloseRepository(),
    ) {
    }

    public function balanceOf(Uuid $storageId, ?LedgerPosition $before = null): LedgerBalance
    {
        $entries = array_values(array_filter(
            $this->entries($storageId),
            static fn (LedgerEntry $entry): bool => null === $before || $entry->position->isBefore($before),
        ));

        $countedAt = null;
        foreach ($entries as $entry) {
            if ($entry->isCount) {
                $countedAt = $entry->position->occurredAt;
            }
        }
        $balance = StorageLedger::balance([], $entries);
        ksort($balance);

        return new LedgerBalance($balance, $countedAt);
    }

    public function entriesAfter(Uuid $storageId, LedgerPosition $after): array
    {
        return array_values(array_filter(
            $this->entries($storageId),
            static fn (LedgerEntry $entry): bool => $after->isBefore($entry->position),
        ));
    }

    /** @return list<LedgerEntry> in ledger order */
    private function entries(Uuid $storageId): array
    {
        $entries = [];
        foreach ($this->movements->inLedgerOrder() as $movement) {
            foreach ($movement->storages() as $storage) {
                if ($storage->getId()->equals($storageId)) {
                    $entries[] = $movement->ledgerEntryFor($storage) ?? throw new \LogicException('Entry expected.');
                }
            }
        }
        foreach ($this->closes->all() as $close) {
            foreach ($close->getStorages() as $summary) {
                if ($summary->getStorage()->getId()->equals($storageId)) {
                    $entries[] = $close->ledgerEntryFor($summary->getStorage()) ?? throw new \LogicException('Entry expected.');
                }
            }
        }
        usort($entries, static fn (LedgerEntry $a, LedgerEntry $b): int => $a->position->isBefore($b->position) ? -1 : ($b->position->isBefore($a->position) ? 1 : 0));

        return $entries;
    }
}
