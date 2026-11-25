<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** Names are unique among the categories with the same parent (or among the top level ones), compared case-insensitively. */
final class ProductCategoryNameTakenException extends PurchasingException
{
    public static function of(string $name, ?string $parentId): self
    {
        return new self(
            null === $parentId
                ? \sprintf('The organization already has a top-level product category named "%s".', $name)
                : \sprintf('Product category "%s" already has a subcategory named "%s".', $parentId, $name),
            ['name' => $name, 'parentId' => $parentId],
        );
    }
}
