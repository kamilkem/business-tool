<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/**
 * The reference quantity is a positive decimal in the base unit of the product's measure, and it is
 * given only for a measure with a unit: a product with measure `none` has none (ADR 0041 §4).
 */
final class InvalidProductReferenceQuantityException extends PurchasingException
{
    public static function notPositive(string $value): self
    {
        return new self(
            \sprintf('The reference quantity must be a positive decimal number, "%s" given.', $value),
            ['referenceQuantity' => $value],
            variant: __FUNCTION__,
        );
    }

    public static function notAllowedForMeasure(string $value, string $measure): self
    {
        return new self(
            \sprintf('A product with measure "%s" has no reference quantity, "%s" given.', $measure, $value),
            ['referenceQuantity' => $value, 'measure' => $measure],
            variant: __FUNCTION__,
        );
    }
}
