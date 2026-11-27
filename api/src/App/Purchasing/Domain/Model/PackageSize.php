<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Expenses\Domain\Model\DecimalString;
use App\Purchasing\Domain\Exception\InvalidBundleCountException;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;

/**
 * What one unit of an item's quantity holds, in the product's base unit (L, kg, pc), as the owner states it
 * (ADR 0041 §3, ADR 0048): the size of one single package, and, for a bundle (a crate of 20 × 0.5 L, a
 * 6-pack), how many of them the unit holds. {@see total()} is the size per unit of the quantity: the single
 * size times the count. Every price, quantity and comparison uses the total; the two numbers are kept so that
 * a bundle is shown, and remembered, as "20 × 0.5 L" and not as 10 L.
 *
 * Exact integer arithmetic on millionths of the base unit, never floats (ADR 0040 §4). A bundled total
 * stays below 10¹² base units.
 */
final readonly class PackageSize
{
    public const int MIN_BUNDLE_COUNT = 2;
    public const int MAX_BUNDLE_COUNT = 9_999;

    private const int MICROS = 1_000_000;

    /** The total of a bundle, in millionths, is below this (10¹² base units). */
    private const int TOTAL_LIMIT_MICROS = 1_000_000_000_000 * self::MICROS;

    /** Integer digits of a single size that can still be multiplied: 10¹² base units. */
    private const int MAX_SINGLE_INTEGER_DIGITS = 12;

    private function __construct(
        /** A positive decimal string in normal form: the size of one single package. */
        public string $single,
        /** Single packages per unit of the quantity; null when the unit is not a bundle. */
        public ?int $bundleCount,
    ) {
    }

    /**
     * @param string $single      a positive decimal with at most 6 decimals
     * @param ?int   $bundleCount null, or the number of single packages, from 2 to 9 999
     *
     * @throws InvalidPackageSizeException when the single size is not a positive decimal
     * @throws InvalidBundleCountException when the count is out of range or the total too large
     */
    public static function of(string $single, ?int $bundleCount = null): self
    {
        $normalized = DecimalString::normalized($single, PurchasedItem::PACKAGE_SIZE_INTEGER_DIGITS, PurchasedItem::PACKAGE_SIZE_SCALE);
        if (null === $normalized || '0' === $normalized || str_starts_with($normalized, '-')) {
            throw InvalidPackageSizeException::of($single);
        }
        if (null !== $bundleCount) {
            if ($bundleCount < self::MIN_BUNDLE_COUNT || $bundleCount > self::MAX_BUNDLE_COUNT) {
                throw InvalidBundleCountException::outOfRange($bundleCount);
            }
            $micros = self::microsOf($normalized);
            if (null === $micros || $micros > intdiv(self::TOTAL_LIMIT_MICROS - 1, $bundleCount)) {
                throw InvalidBundleCountException::tooLarge($normalized, $bundleCount);
            }
        }

        return new self($normalized, $bundleCount);
    }

    /** A size per unit of the quantity with no count (a total as the model or a name gives it); null when it is not a valid size. */
    public static function ofTotal(string $total): ?self
    {
        try {
            return self::of($total);
        } catch (InvalidPackageSizeException) {
            return null;
        }
    }

    /** The size per unit of the quantity: the single size times the count (the single size for a unit that is no bundle), in normal form. */
    public function total(): string
    {
        if (null === $this->bundleCount) {
            return $this->single;
        }
        $single = self::microsOf($this->single) ?? throw new \LogicException('The constructor checked that a bundled size fits.');

        return self::toDecimal($single * $this->bundleCount);
    }

    public function sameTotalAs(self $other): bool
    {
        return $this->total() === $other->total();
    }

    /** `0.7`, `6 × 0.5`: for messages and logs, not for screens. */
    public function __toString(): string
    {
        return null === $this->bundleCount ? $this->single : $this->bundleCount.' × '.$this->single;
    }

    /** A normalized decimal as millionths; null when its integer part is too long to multiply. */
    private static function microsOf(string $decimal): ?int
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        if (\strlen($whole) > self::MAX_SINGLE_INTEGER_DIGITS) {
            return null;
        }

        return (int) $whole * self::MICROS + (int) str_pad($fraction, 6, '0');
    }

    private static function toDecimal(int $micros): string
    {
        $text = \sprintf('%d.%06d', intdiv($micros, self::MICROS), $micros % self::MICROS);

        return rtrim(rtrim($text, '0'), '.');
    }
}
