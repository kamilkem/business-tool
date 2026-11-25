<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** No such purchased item in this organization (another organization's is not found either). */
final class PurchasedItemNotFoundException extends PurchasingException
{
    public static function withId(string $itemId): self
    {
        return new self(\sprintf('Purchased item "%s" not found.', $itemId), ['itemId' => $itemId]);
    }
}
