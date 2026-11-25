<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\CreateProduct;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager creates a product. The measure is a ProductMeasure value; the reference
 * quantity a decimal string, null for the default (1) of a measure with a unit and for `none`.
 */
final readonly class CreateProductCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
        public string $name,
        public string $measure,
        public ?string $referenceQuantity = null,
        public ?string $categoryId = null,
        public bool $excluded = false,
    ) {
    }
}
