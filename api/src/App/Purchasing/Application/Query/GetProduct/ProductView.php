<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProduct;

use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;

final readonly class ProductView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $categoryId,
        public ProductMeasure $measure,
        /** A decimal string in the measure's base unit; null exactly when the measure is `none`. */
        public ?string $referenceQuantity,
        public bool $excluded,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromProduct(Product $product): self
    {
        return new self(
            $product->getId()->toRfc4122(),
            $product->getName(),
            $product->getCategory()?->getId()->toRfc4122(),
            $product->getMeasure(),
            $product->getReferenceQuantity(),
            $product->isExcluded(),
            $product->getCreatedAt(),
        );
    }
}
