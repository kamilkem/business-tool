<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Exception\ProductCategoryInUseException;
use App\Purchasing\Domain\Exception\ProductCategoryNameTakenException;
use App\Purchasing\Domain\Model\ProductCategory;
use Symfony\Component\Uid\Uuid;

/** Product categories of the current organization only (TenantFilter). */
interface ProductCategoryRepositoryInterface
{
    public function findById(Uuid $id): ?ProductCategory;

    /**
     * The category with this name, compared case-insensitively, under this parent (null: the top
     * level): for ProductCategory::create() and change().
     */
    public function findSibling(?Uuid $parentId, string $name): ?ProductCategory;

    public function hasSubcategories(Uuid $id): bool;

    /**
     * By name, case-insensitively.
     *
     * @return list<ProductCategory>
     */
    public function findAll(): array;

    /** @throws ProductCategoryNameTakenException when a sibling with the name was stored in the meantime */
    public function add(ProductCategory $category): void;

    /**
     * Persists changes made to an already managed category.
     *
     * @throws ProductCategoryNameTakenException when a sibling with the new name was stored in the meantime
     */
    public function save(ProductCategory $category): void;

    /**
     * The foreign keys of subcategories and products refuse a category that was given one in the meantime.
     *
     * @throws ProductCategoryInUseException when that happened
     */
    public function remove(ProductCategory $category): void;
}
