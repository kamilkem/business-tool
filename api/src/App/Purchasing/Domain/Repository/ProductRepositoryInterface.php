<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Exception\ProductInUseException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Model\Product;
use Symfony\Component\Uid\Uuid;

/** Products of the current organization only (TenantFilter). */
interface ProductRepositoryInterface
{
    public function findById(Uuid $id): ?Product;

    /** The product with this name, compared case-insensitively: for Product::create() and change(). */
    public function findByName(string $name): ?Product;

    /** Whether a product is in this category. */
    public function existsInCategory(Uuid $categoryId): bool;

    /**
     * By name, case-insensitively.
     *
     * @param list<Uuid>|null $categoryIds only the products in one of these categories; null: any
     * @param ?string         $nameContains only the products whose name contains this, case-insensitively
     *
     * @return list<Product>
     */
    public function search(?array $categoryIds, ?string $nameContains): array;

    /** @throws ProductNameTakenException when a product with the name was stored in the meantime */
    public function add(Product $product): void;

    /**
     * Persists changes made to an already managed product.
     *
     * @throws ProductNameTakenException when a product with the new name was stored in the meantime
     */
    public function save(Product $product): void;

    /**
     * @throws ProductInUseException when an item was linked to the product in the meantime (the foreign key refuses it)
     */
    public function remove(Product $product): void;
}
