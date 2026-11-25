<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductCategory;

use App\Purchasing\Domain\Model\ProductCategory;

final readonly class ProductCategoryView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromCategory(ProductCategory $category): self
    {
        return new self(
            $category->getId()->toRfc4122(),
            $category->getName(),
            $category->getParent()?->getId()->toRfc4122(),
            $category->getCreatedAt(),
        );
    }
}
