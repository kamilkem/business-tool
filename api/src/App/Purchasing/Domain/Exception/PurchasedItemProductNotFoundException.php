<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** The product an item is to be linked to is not one of this organization's (another organization's is not found either). */
final class PurchasedItemProductNotFoundException extends PurchasingException
{
    public static function withId(string $productId): self
    {
        return new self(\sprintf('The product "%s" to link the items to was not found.', $productId), ['productId' => $productId]);
    }
}
