<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\AddProductCategory;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager adds a product category under another one, or at the top level with a null parent. */
final readonly class AddProductCategoryCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
        public string $name,
        public ?string $parentId = null,
    ) {
    }
}
