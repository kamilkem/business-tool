<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Model\SellerUnit;

/** Seller units of the current organization only (TenantFilter). */
interface SellerUnitRepositoryInterface
{
    /**
     * @param list<array{seller: string, unit: string}> $pairs seller keys and unit keys
     *
     * @return list<SellerUnit>
     */
    public function findByPairs(array $pairs): array;

    /**
     * Stores new units and the changes made to managed ones in one flush. The unique constraint on
     * (organization, seller, unit) refuses one added in the meantime.
     *
     * @param list<SellerUnit> $units
     */
    public function saveAll(array $units): void;
}
