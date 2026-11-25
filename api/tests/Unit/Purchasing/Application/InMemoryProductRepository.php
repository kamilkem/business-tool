<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<string, Product> */
    private array $byId = [];

    public function findById(Uuid $id): ?Product
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByName(string $name): ?Product
    {
        foreach ($this->byId as $product) {
            if (Product::nameKeyOf($product->getName()) === Product::nameKeyOf($name)) {
                return $product;
            }
        }

        return null;
    }

    public function existsInCategory(Uuid $categoryId): bool
    {
        foreach ($this->byId as $product) {
            if ($product->getCategory()?->getId()->equals($categoryId)) {
                return true;
            }
        }

        return false;
    }

    public function search(?array $categoryIds, ?string $nameContains): array
    {
        $products = array_values(array_filter($this->byId, static function (Product $product) use ($categoryIds, $nameContains): bool {
            $category = $product->getCategory()?->getId();
            if (null !== $categoryIds && (null === $category || [] === array_filter($categoryIds, static fn (Uuid $id): bool => $id->equals($category)))) {
                return false;
            }

            return null === $nameContains || '' === trim($nameContains) || str_contains(Product::nameKeyOf($product->getName()), Product::nameKeyOf($nameContains));
        }));
        usort($products, static fn (Product $a, Product $b): int => Product::nameKeyOf($a->getName()) <=> Product::nameKeyOf($b->getName()));

        return $products;
    }

    public function add(Product $product): void
    {
        $this->byId[$product->getId()->toRfc4122()] = $product;
    }

    public function save(Product $product): void
    {
    }

    public function remove(Product $product): void
    {
        unset($this->byId[$product->getId()->toRfc4122()]);
    }
}
