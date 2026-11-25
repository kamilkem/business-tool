<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** The package size is a positive decimal in the product's base unit, with at most 6 decimals (ADR 0041 §3). */
final class InvalidPackageSizeException extends PurchasingException
{
    public static function of(string $value): self
    {
        return new self(
            \sprintf('The package size must be a positive decimal number with at most 6 decimals, "%s" given.', $value),
            ['packageSize' => $value],
        );
    }
}
