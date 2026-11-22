<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\Event\CashLedgerChanged;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\LedgerEntry;
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
 * stores or removes the movement, and publishes CashLedgerChanged, from which #189 recomputes the
 * first close after it on each storage.
 *
 * Two writes at the same time may each pass the check alone; the buses run without transactions
 * (ADR 0004), and the volumes (a few movements a day, by owners and managers) make that unlikely.
 */
final readonly class CashLedgerWriter
{
    public function __construct(
        private CashMovementRepositoryInterface $movements,
        private CashStorageRepositoryInterface $storages,
        private DenominationRepositoryInterface $denominations,
        private EventPublisherInterface $events,
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
     */
    public function record(CashMovement $movement, string $organizationId, string $actorId): void
    {
        foreach ($movement->storages() as $storage) {
            $entry = $movement->ledgerEntryFor($storage) ?? throw new \LogicException('The movement has a line on the storage.');
            $this->assertNeverNegative($storage, $movement, $entry);
        }

        $this->movements->add($movement);
        $this->published($movement, $organizationId, $actorId);
    }

    /**
     * @throws StorageBalanceNegativeException when a deposit storage would go below zero without it
     */
    public function delete(CashMovement $movement, string $organizationId, string $actorId): void
    {
        foreach ($movement->storages() as $storage) {
            $this->assertNeverNegative($storage, $movement, null);
        }

        $this->movements->remove($movement);
        $this->published($movement, $organizationId, $actorId);
    }

    /**
     * The ledger before the movement is unchanged; from its moment on, the deposit storage holds its
     * balance just before it, then the movement's entry ($entry, or nothing when it is deleted), then
     * everything after it.
     *
     * @throws StorageBalanceNegativeException
     */
    private function assertNeverNegative(CashStorage $storage, CashMovement $movement, ?LedgerEntry $entry): void
    {
        if (CashStorageMode::Deposit !== $storage->getMode()) {
            return;
        }

        $position = $movement->position();
        $opening = $this->movements->balanceOf($storage->getId(), $position)->pieces;
        $entries = $this->movements->entriesAfter($storage->getId(), $position);
        if (null !== $entry) {
            array_unshift($entries, $entry);
        }

        $ids = array_keys($opening);
        foreach ($entries as $each) {
            $ids = [...$ids, ...array_keys($each->pieces)];
        }
        $denominations = $this->denominations->findByIds(array_values(array_unique(array_map(strval(...), $ids))));
        foreach ($movement->denominations() as $denomination) {
            $denominations[$denomination->getId()->toRfc4122()] ??= $denomination;
        }

        StorageLedger::assertNeverNegative($storage, $opening, $entries, $denominations);
    }

    private function published(CashMovement $movement, string $organizationId, string $actorId): void
    {
        $this->events->publish(new CashLedgerChanged(
            $organizationId,
            $movement->getId()->toRfc4122(),
            array_map(static fn (CashStorage $storage): string => $storage->getId()->toRfc4122(), $movement->storages()),
            $movement->getOccurredAt()->format(\DATE_ATOM),
            $actorId,
        ));
    }
}
