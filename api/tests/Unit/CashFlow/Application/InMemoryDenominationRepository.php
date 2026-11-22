<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryDenominationRepository implements DenominationRepositoryInterface
{
    /** @var array<string, Denomination> */
    private array $byId = [];

    /** @var array<string, true> ids that tests mark as used by a close or movement */
    private array $used = [];

    public function markUsed(Denomination $denomination): void
    {
        $this->used[$denomination->getId()->toRfc4122()] = true;
    }

    public function findById(Uuid $id): ?Denomination
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByValue(string $currency, int $value): ?Denomination
    {
        foreach ($this->byId as $denomination) {
            if ($denomination->getCurrency() === strtoupper($currency) && $denomination->getValue() === $value) {
                return $denomination;
            }
        }

        return null;
    }

    public function findByCurrency(string $currency, bool $includeDeactivated = false): array
    {
        $list = array_values(array_filter(
            $this->byId,
            static fn (Denomination $d): bool => $d->getCurrency() === strtoupper($currency) && ($includeDeactivated || $d->isActive()),
        ));
        usort($list, Denomination::compare(...));

        return $list;
    }

    public function findByIds(array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            if (Uuid::isValid($id) && isset($this->byId[Uuid::fromString($id)->toRfc4122()])) {
                $found[Uuid::fromString($id)->toRfc4122()] = $this->byId[Uuid::fromString($id)->toRfc4122()];
            }
        }

        return $found;
    }

    public function isUsed(Uuid $id): bool
    {
        return isset($this->used[$id->toRfc4122()]);
    }

    public function usedIds(): array
    {
        return array_keys($this->used);
    }

    public function add(Denomination $denomination): void
    {
        $this->byId[$denomination->getId()->toRfc4122()] = $denomination;
    }

    public function save(Denomination $denomination): void
    {
    }

    public function remove(Denomination $denomination): void
    {
        unset($this->byId[$denomination->getId()->toRfc4122()]);
    }
}
