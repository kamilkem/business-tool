<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

final class ProductMergeIntoItselfException extends PurchasingException
{
    public static function of(string $productId): self
    {
        return new self(\sprintf('Product "%s" cannot be merged into itself.', $productId), ['productId' => $productId]);
    }
}
