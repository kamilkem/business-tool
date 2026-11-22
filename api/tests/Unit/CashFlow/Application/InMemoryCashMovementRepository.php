<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\LedgerBalance;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Model\StorageLedger;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\Shared\Domain\Time\UtcRange;
use Symfony\Component\Uid\Uuid;

/**
 * Fake implementing the port for one organization: no container, no database. Its ledger queries
 * follow the domain's rules (StorageLedger); CashMovementRepositoryTest checks the SQL ones.
 */
final class InMemoryCashMovementRepository implements CashMovementRepositoryInterface
{
    /** @var array<string, CashMovement> */
    private array $byId = [];

    public function findById(Uuid $id): ?CashMovement
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findOccurredIn(UtcRange $range, ?Uuid $storageId = null): array
    {
        $found = array_filter(
            $this->inLedgerOrder(),
            static fn (CashMovement $movement): bool => $range->contains($movement->getOccurredAt())
                && (null === $storageId || [] !== array_filter($movement->storages(), static fn ($storage): bool => $storage->getId()->equals($storageId))),
        );

        return array_reverse(array_values($found));
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

    public function add(CashMovement $movement): void
    {
        $this->byId[$movement->getId()->toRfc4122()] = $movement;
    }

    public function remove(CashMovement $movement): void
    {
        unset($this->byId[$movement->getId()->toRfc4122()]);
    }

    /** @return list<LedgerEntry> */
    private function entries(Uuid $storageId): array
    {
        $entries = [];
        foreach ($this->inLedgerOrder() as $movement) {
            foreach ($movement->storages() as $storage) {
                if ($storage->getId()->equals($storageId)) {
                    $entries[] = $movement->ledgerEntryFor($storage) ?? throw new \LogicException('Entry expected.');
                }
            }
        }

        return $entries;
    }

    /** @return list<CashMovement> */
    private function inLedgerOrder(): array
    {
        $movements = array_values($this->byId);
        usort($movements, static fn (CashMovement $a, CashMovement $b): int => $a->position()->isBefore($b->position()) ? -1 : ($b->position()->isBefore($a->position()) ? 1 : 0));

        return $movements;
    }
}
