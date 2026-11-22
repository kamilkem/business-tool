<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryCashStorageRepository implements CashStorageRepositoryInterface
{
    /** @var array<string, CashStorage> */
    private array $byId = [];

    /** @var array<string, true> ids that tests mark as used by a close or movement */
    private array $used = [];

    public function markUsed(CashStorage $storage): void
    {
        $this->used[$storage->getId()->toRfc4122()] = true;
    }

    public function findById(Uuid $id): ?CashStorage
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByName(string $name): ?CashStorage
    {
        foreach ($this->byId as $storage) {
            if ($storage->getName() === $name) {
                return $storage;
            }
        }

        return null;
    }

    public function findAll(bool $includeDeactivated = false): array
    {
        $list = array_values(array_filter($this->byId, static fn (CashStorage $s): bool => $includeDeactivated || $s->isActive()));
        usort($list, CashStorage::compare(...));

        return $list;
    }

    public function isUsed(Uuid $id): bool
    {
        return isset($this->used[$id->toRfc4122()]);
    }

    public function usedIds(): array
    {
        return array_keys($this->used);
    }

    public function add(CashStorage $storage): void
    {
        $this->byId[$storage->getId()->toRfc4122()] = $storage;
    }

    public function save(CashStorage $storage): void
    {
    }

    public function remove(CashStorage $storage): void
    {
        unset($this->byId[$storage->getId()->toRfc4122()]);
    }
}
