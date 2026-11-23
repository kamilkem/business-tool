<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\Exception\IncomeMonthClosedException;
use App\CashFlow\Domain\CashLedgerInterface;
use App\CashFlow\Domain\Event\CashLedgerChanged;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Model\MovementPieces;
use App\CashFlow\Domain\Model\StorageLedger;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What every write to the cash ledger shares (ADR 0033 §4): it finds the storages and denominations
 * a command names, checks that no deposit storage goes below zero from the movement's moment on,
 * stores or removes the movement, and publishes CashLedgerChanged, from which the first close after
 * it on each storage is recomputed (§7). A corrected or deleted close is checked the same way.
 *
 * Two writes at the same time may each pass the check alone; the buses run without transactions
 * (ADR 0004), and the volumes (a few movements a day, by owners and managers) make that unlikely.
 */
final readonly class CashLedgerWriter
{
    public function __construct(
        private CashMovementRepositoryInterface $movements,
        private CashLedgerInterface $ledger,
        private CashStorageRepositoryInterface $storages,
        private DenominationRepositoryInterface $denominations,
        private EventPublisherInterface $events,
        private IncomeMonthLock $incomeMonths,
    ) {
    }

    /** @throws StorageNotFoundException when the storage is not one of the organization's */
    public function storage(string $storageId): CashStorage
    {
        $storage = Uuid::isValid($storageId) ? $this->storages->findById(Uuid::fromString($storageId)) : null;

        return $storage ?? throw StorageNotFoundException::withId($storageId);
    }

    /**
     * @param list<array{denominationId: string, pieces: int}> $lines
     *
     * @return list<MovementPieces>
     *
     * @throws DenominationNotFoundException when a denomination is not one of the organization's
     */
    public function pieces(array $lines): array
    {
        $found = $this->denominations->findByIds(array_column($lines, 'denominationId'));

        return array_map(
            static fn (array $line): MovementPieces => new MovementPieces(
                $found[Uuid::isValid($line['denominationId']) ? Uuid::fromString($line['denominationId'])->toRfc4122() : ''] ?? throw DenominationNotFoundException::withId($line['denominationId']),
                $line['pieces'],
            ),
            $lines,
        );
    }

    /**
     * @throws StorageBalanceNegativeException when a deposit storage would go below zero
     * @throws IncomeMonthClosedException      when it would change the cash income of a close in a closed Income month
     */
    public function record(CashMovement $movement, string $organizationId, string $actorId): void
    {
        $this->incomeMonths->assertNoClosedCloseChanges($movement->position(), $movement->storages(), $movement->ledgerEntryFor(...));
        foreach ($movement->storages() as $storage) {
            $entry = $movement->ledgerEntryFor($storage) ?? throw new \LogicException('The movement has a line on the storage.');
            $this->assertNeverNegative($storage, $movement->position(), $entry, $movement->denominations());
        }

        $this->movements->add($movement);
        $this->published($organizationId, $movement->getId(), $movement->storages(), $movement->getOccurredAt(), $actorId);
    }

    /**
     * @throws StorageBalanceNegativeException when a deposit storage would go below zero without it
     * @throws IncomeMonthClosedException      when it would change the cash income of a close in a closed Income month
     */
    public function delete(CashMovement $movement, string $organizationId, string $actorId): void
    {
        $this->incomeMonths->assertNoClosedCloseChanges($movement->position(), $movement->storages(), static fn (): ?LedgerEntry => null);
        foreach ($movement->storages() as $storage) {
            $this->assertNeverNegative($storage, $movement->position(), null, $movement->denominations());
        }

        $this->movements->remove($movement);
        $this->published($organizationId, $movement->getId(), $movement->storages(), $movement->getOccurredAt(), $actorId);
    }

    /**
     * A corrected close, before it is saved: no deposit storage goes below zero with what it now
     * does to it (ADR 0033 §4, §7), and no close of a closed Income month gets another cash income
     * (ADR 0034 §3).
     *
     * @throws StorageBalanceNegativeException
     * @throws IncomeMonthClosedException
     */
    public function assertCorrectedCloseNeverNegative(DayClose $close): void
    {
        $this->incomeMonths->assertNoClosedCloseChanges($close->position(), $close->coveredStorages(), $close->ledgerEntryFor(...));
        foreach ($close->coveredStorages() as $storage) {
            $this->assertNeverNegative($storage, $close->position(), $close->ledgerEntryFor($storage), $close->denominations());
        }
    }

    /**
     * A close about to be deleted: no deposit storage goes below zero without it, and no close of a
     * closed Income month gets another cash income (ADR 0034 §3).
     *
     * @throws StorageBalanceNegativeException
     * @throws IncomeMonthClosedException
     */
    public function assertDeletedCloseNeverNegative(DayClose $close): void
    {
        $this->incomeMonths->assertNoClosedCloseChanges($close->position(), $close->coveredStorages(), static fn (): ?LedgerEntry => null);
        foreach ($close->coveredStorages() as $storage) {
            $this->assertNeverNegative($storage, $close->position(), null, $close->denominations());
        }
    }

    /** A close was corrected or deleted, and that is saved: the next close of each of its storages is recomputed. */
    public function closeChanged(DayClose $close, string $organizationId, string $actorId): void
    {
        $this->published($organizationId, $close->getId(), $close->coveredStorages(), $close->getClosedAt(), $actorId);
    }

    /**
     * The ledger before the record is unchanged; from its position on, the deposit storage holds its
     * balance just before it, then the record's entry ($entry, or nothing when it is deleted), then
     * everything after it.
     *
     * @param list<Denomination> $denominations the record's, which the ledger may no longer have
     *
     * @throws StorageBalanceNegativeException
     */
    private function assertNeverNegative(CashStorage $storage, LedgerPosition $position, ?LedgerEntry $entry, array $denominations): void
    {
        if (CashStorageMode::Deposit !== $storage->getMode()) {
            return;
        }

        $opening = $this->ledger->balanceOf($storage->getId(), $position)->pieces;
        $entries = $this->ledger->entriesAfter($storage->getId(), $position);
        if (null !== $entry) {
            array_unshift($entries, $entry);
        }

        $ids = array_keys($opening);
        foreach ($entries as $each) {
            $ids = [...$ids, ...array_keys($each->pieces)];
        }
        $found = $this->denominations->findByIds(array_values(array_unique(array_map(strval(...), $ids))));
        foreach ($denominations as $denomination) {
            $found[$denomination->getId()->toRfc4122()] ??= $denomination;
        }

        StorageLedger::assertNeverNegative($storage, $opening, $entries, $found);
    }

    /** @param list<CashStorage> $storages */
    private function published(string $organizationId, Uuid $recordId, array $storages, \DateTimeImmutable $occurredAt, string $actorId): void
    {
        $this->events->publish(new CashLedgerChanged(
            $organizationId,
            $recordId->toRfc4122(),
            array_map(static fn (CashStorage $storage): string => $storage->getId()->toRfc4122(), $storages),
            $occurredAt->format(\DATE_ATOM),
            $actorId,
        ));
    }
}
