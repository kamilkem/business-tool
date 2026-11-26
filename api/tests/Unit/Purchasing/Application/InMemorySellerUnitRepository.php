<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Purchasing\Domain\Model\SellerUnit;
use App\Purchasing\Domain\Repository\SellerUnitRepositoryInterface;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemorySellerUnitRepository implements SellerUnitRepositoryInterface
{
    /** @var array<string, SellerUnit> by seller and unit */
    private array $byKey = [];

    public int $saveAllCallCount = 0;

    public function findByPairs(array $pairs): array
    {
        $found = [];
        foreach ($pairs as $pair) {
            if (isset($this->byKey[$pair['seller'].'#'.$pair['unit']])) {
                $found[] = $this->byKey[$pair['seller'].'#'.$pair['unit']];
            }
        }

        return $found;
    }

    public function saveAll(array $units): void
    {
        ++$this->saveAllCallCount;
        foreach ($units as $unit) {
            $this->byKey[$unit->getSellerKey().'#'.$unit->getUnitKey()] = $unit;
        }
    }

    /** @return list<SellerUnit> */
    public function all(): array
    {
        return array_values($this->byKey);
    }
}
