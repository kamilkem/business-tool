<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Repository;

use App\CashFlow\Domain\Exception\StorageInUseException;
use App\CashFlow\Domain\Exception\StorageNameTakenException;
use App\CashFlow\Domain\Model\CashStorage;
use Symfony\Component\Uid\Uuid;

/** Cash storages of the current organization only (TenantFilter). */
interface CashStorageRepositoryInterface
{
    public function findById(Uuid $id): ?CashStorage;

    /** The storage with this name, deactivated or not: for CashStorage::create() and rename(). */
    public function findByName(string $name): ?CashStorage;

    /**
     * Active storages first, each group by name (CashStorage::compare()).
     *
     * @return list<CashStorage>
     */
    public function findAll(bool $includeDeactivated = false): array;

    /** Whether a close or movement refers to the storage. */
    public function isUsed(Uuid $id): bool;

    /** @return list<string> the ids of the storages that a close or movement refers to */
    public function usedIds(): array;

    /** @throws StorageNameTakenException when a storage with the name was stored in the meantime */
    public function add(CashStorage $storage): void;

    /**
     * Persists changes made to an already managed storage.
     *
     * @throws StorageNameTakenException when a storage with the new name was stored in the meantime
     */
    public function save(CashStorage $storage): void;

    /** @throws StorageInUseException when a close or movement started referring to it in the meantime */
    public function remove(CashStorage $storage): void;
}
