<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** A product is deleted only while no purchased item refers to it (ADR 0041 §4); merge it into another one instead. */
final class ProductInUseException extends PurchasingException
{
    public static function of(string $productId): self
    {
        return new self(\sprintf('Product "%s" has purchased items: merge it into another product instead.', $productId), ['productId' => $productId]);
    }
}
