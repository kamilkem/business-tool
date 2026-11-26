<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Model\ProductAlias;
use Symfony\Component\Uid\Uuid;

/** Aliases of the current organization only (TenantFilter). */
interface ProductAliasRepositoryInterface
{
    /**
     * The aliases for these keys, in any order.
     *
     * @param list<array{kind: string, key: string}> $keys AliasKind values and keys
     *
     * @return list<ProductAlias>
     */
    public function findByKeys(array $keys): array;

    /**
     * Stores the new aliases and the changes made to managed ones in one flush. The unique constraint on
     * (organization, kind, key) refuses an alias added in the meantime.
     *
     * @param list<ProductAlias> $aliases
     */
    public function saveAll(array $aliases): void;

    /**
     * Gives the aliases of one product to another, the move of a merge (ADR 0041 §4). One statement; both
     * products must be the current organization's. A key is one alias per organization, so the two
     * products never share one.
     */
    public function moveAliases(Uuid $fromProductId, Uuid $toProductId): void;
}
