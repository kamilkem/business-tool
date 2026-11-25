<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** The parent a request names is not a product category of this organization (a problem of the body, not of the URL). */
final class ProductCategoryParentNotFoundException extends PurchasingException
{
    public static function withId(string $parentId): self
    {
        return new self(\sprintf('The parent product category "%s" does not exist.', $parentId), ['parentId' => $parentId]);
    }
}
