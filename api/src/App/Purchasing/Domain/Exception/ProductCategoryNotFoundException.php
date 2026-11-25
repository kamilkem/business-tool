<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** No such product category in this organization (another organization's is not found either). */
final class ProductCategoryNotFoundException extends PurchasingException
{
    public static function withId(string $categoryId): self
    {
        return new self(\sprintf('Product category "%s" not found.', $categoryId), ['categoryId' => $categoryId]);
    }
}
