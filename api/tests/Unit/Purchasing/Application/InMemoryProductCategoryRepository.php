<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryProductCategoryRepository implements ProductCategoryRepositoryInterface
{
    /** @var array<string, ProductCategory> */
    private array $byId = [];

    public function findById(Uuid $id): ?ProductCategory
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findSibling(?Uuid $parentId, string $name): ?ProductCategory
    {
        foreach ($this->byId as $category) {
            if ($category->getParent()?->getId()->toRfc4122() === $parentId?->toRfc4122() && ProductCategory::nameKeyOf($category->getName()) === ProductCategory::nameKeyOf($name)) {
                return $category;
            }
        }

        return null;
    }

    public function hasSubcategories(Uuid $id): bool
    {
        foreach ($this->byId as $category) {
            if ($category->getParent()?->getId()->equals($id)) {
                return true;
            }
        }

        return false;
    }

    public function findAll(): array
    {
        $categories = array_values($this->byId);
        usort($categories, static fn (ProductCategory $a, ProductCategory $b): int => ProductCategory::nameKeyOf($a->getName()) <=> ProductCategory::nameKeyOf($b->getName()));

        return $categories;
    }

    public function add(ProductCategory $category): void
    {
        $this->byId[$category->getId()->toRfc4122()] = $category;
    }

    public function save(ProductCategory $category): void
    {
    }

    public function remove(ProductCategory $category): void
    {
        unset($this->byId[$category->getId()->toRfc4122()]);
    }
}
