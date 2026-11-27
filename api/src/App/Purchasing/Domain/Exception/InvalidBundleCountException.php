<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/**
 * A bundle count is a whole number of single packages, from 2 to 9 999, and goes with the package size of one
 * single package; the size per unit of the quantity (size × count) stays below 10¹² base units (ADR 0048 §2).
 */
final class InvalidBundleCountException extends PurchasingException
{
    public static function outOfRange(int $bundleCount): self
    {
        return new self(
            \sprintf('The bundle count must be a whole number from 2 to 9999, %d given.', $bundleCount),
            ['bundleCount' => $bundleCount],
            variant: __FUNCTION__,
        );
    }

    public static function withoutPackageSize(int $bundleCount): self
    {
        return new self(
            \sprintf('A bundle of %d needs the package size of one single package.', $bundleCount),
            ['bundleCount' => $bundleCount],
            variant: __FUNCTION__,
        );
    }

    public static function tooLarge(string $packageSize, int $bundleCount): self
    {
        return new self(
            \sprintf('%d packages of %s are too much for one unit of the quantity.', $bundleCount, $packageSize),
            ['packageSize' => $packageSize, 'bundleCount' => $bundleCount],
            variant: __FUNCTION__,
        );
    }
}
