<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** The category a request puts a product in is not one of this organization's (a problem of the body, not of the URL). */
final class UnknownProductCategoryException extends PurchasingException
{
    public static function withId(string $categoryId): self
    {
        return new self(\sprintf('The product category "%s" does not exist.', $categoryId), ['categoryId' => $categoryId]);
    }
}
