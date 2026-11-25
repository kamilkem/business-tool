<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\UpdateProduct;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager changes a product: its name, category, measure, reference quantity and whether
 * it is excluded from the analysis. Every value is the new one: the caller sends the current value
 * of what it does not change. A null reference quantity is the default (1) for a measure with a
 * unit, and the only value for `none`.
 */
final readonly class UpdateProductCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
        public string $name,
        public ?string $categoryId,
        public string $measure,
        public ?string $referenceQuantity,
        public bool $excluded,
    ) {
    }
}
