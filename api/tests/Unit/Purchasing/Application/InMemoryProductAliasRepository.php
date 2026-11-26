<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Repository\ProductAliasRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryProductAliasRepository implements ProductAliasRepositoryInterface
{
    /** @var array<string, ProductAlias> by kind and key */
    private array $byKey = [];

    public int $saveAllCallCount = 0;

    /** @var list<array{string, string}> product ids moved from and to */
    public array $moves = [];

    public function findByKeys(array $keys): array
    {
        $found = [];
        foreach ($keys as $key) {
            if (isset($this->byKey[$key['kind'].'#'.$key['key']])) {
                $found[] = $this->byKey[$key['kind'].'#'.$key['key']];
            }
        }

        return $found;
    }

    public function saveAll(array $aliases): void
    {
        ++$this->saveAllCallCount;
        foreach ($aliases as $alias) {
            $this->byKey[$alias->getKind()->value.'#'.$alias->getKey()] = $alias;
        }
    }

    public function moveAliases(Uuid $fromProductId, Uuid $toProductId): void
    {
        // The statement itself is the adapter's, tested against the database; here the move is recorded.
        $this->moves[] = [$fromProductId->toRfc4122(), $toProductId->toRfc4122()];
    }

    /** @return list<ProductAlias> */
    public function all(): array
    {
        return array_values($this->byKey);
    }
}
