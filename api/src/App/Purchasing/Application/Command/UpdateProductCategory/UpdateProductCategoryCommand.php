<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\UpdateProductCategory;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager renames a product category and moves it under another one, or to the top
 * level with a null parent. Both values are the new ones: the caller sends the current value of
 * what it does not change.
 */
final readonly class UpdateProductCategoryCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
        public string $name,
        public ?string $parentId,
    ) {
    }
}
