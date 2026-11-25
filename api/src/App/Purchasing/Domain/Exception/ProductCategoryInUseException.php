<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** A category is deleted only while it has no subcategory and no product; there is no archive (ADR 0041 §5). */
final class ProductCategoryInUseException extends PurchasingException
{
    public static function hasSubcategories(string $categoryId): self
    {
        return new self(
            \sprintf('Product category "%s" has subcategories: move or delete them first.', $categoryId),
            ['categoryId' => $categoryId],
            variant: __FUNCTION__,
        );
    }

    public static function hasProducts(string $categoryId): self
    {
        return new self(
            \sprintf('Product category "%s" has products: move them to another category first.', $categoryId),
            ['categoryId' => $categoryId],
            variant: __FUNCTION__,
        );
    }
}
