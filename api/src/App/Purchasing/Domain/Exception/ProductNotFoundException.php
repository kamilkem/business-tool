<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** No such product in this organization (another organization's is not found either). */
final class ProductNotFoundException extends PurchasingException
{
    public static function withId(string $productId): self
    {
        return new self(\sprintf('Product "%s" not found.', $productId), ['productId' => $productId]);
    }
}
