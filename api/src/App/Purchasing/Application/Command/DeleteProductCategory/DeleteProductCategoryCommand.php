<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\DeleteProductCategory;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a product category that has no subcategory and no product (ADR 0041 §5). */
final readonly class DeleteProductCategoryCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
    ) {
    }
}
