<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Purchasing\Domain\Model\PurchasedItem;

/**
 * The price sanity check (ADR 0045 §2): a wrong multipack or unit reading gives a package size that is
 * off by the pack's count, and so a price per base unit (litre, kilogram) several times too high or too
 * low. The price per base unit of an item is compared with the median of the product's confirmed items
 * (the owner's word), in the same currency, and an `assigned` match far from it is only `suggested`.
 *
 * Pure rules over integers: a price is the item's net amount in minor units per base unit, scaled by
 * SCALE (thousandths of a minor unit), and never a float (ADR 0010). What does not fit in an integer, or
 * is not a plain purchase (a correction, a zero or negative amount, a missing quantity or size), has no
 * price and is never checked, and never part of the sample.
 */
final class PriceSanity
{
    /** Prices are thousandths of a minor unit per base unit. */
    public const int SCALE = 1_000;

    /** Fewer confirmed prices than this say nothing about a product: no check. */
    public const int MIN_SAMPLE = 5;

    /** A price more than this many times the median, or less than the median divided by it, is an outlier. */
    public const int MAX_RATIO = 3;

    /** The most recent confirmed items of a product that make the sample, so that old prices do not weigh forever. */
    public const int MAX_SAMPLE = 200;

    private const int MICROS = 1_000_000;

    private function __construct()
    {
    }

    /**
     * The price per base unit of an item that has the package size, or null when it has none to check.
     *
     * @param ?string $packageSize a positive decimal string, as PurchasedItem stores it
     */
    public static function of(PurchasedItem $item, ?string $packageSize): ?int
    {
        $kind = PurchaseInvoiceKind::tryFrom($item->getInvoiceKind());
        if ($item->isBeforeCorrection() || (null !== $kind && $kind->isCorrection())) {
            return null;
        }

        return self::perBaseUnit($item->getNetValue(), $item->getQuantity(), $packageSize);
    }

    /**
     * Net amount (minor units) divided by quantity times package size, in SCALE parts of a minor unit,
     * rounded half up; null when a figure is missing, not positive, or the arithmetic would not fit.
     */
    public static function perBaseUnit(?int $netAmount, ?string $quantity, ?string $packageSize): ?int
    {
        if (null === $netAmount || $netAmount <= 0 || null === $quantity || null === $packageSize) {
            return null;
        }
        $quantityMicros = self::micros($quantity);
        $sizeMicros = self::micros($packageSize);
        if (null === $quantityMicros || null === $sizeMicros || $quantityMicros <= 0 || $sizeMicros <= 0) {
            return null;
        }

        // Guards against overflow, which PHP would turn into a float: both products must fit an integer.
        if ($quantityMicros > intdiv(\PHP_INT_MAX - self::MICROS, $sizeMicros)
            || $netAmount > intdiv(\PHP_INT_MAX - self::MICROS, self::SCALE * self::MICROS)) {
            return null;
        }
        // Micro base units, rounded: quantity x size has twelve decimals.
        $baseMicros = intdiv($quantityMicros * $sizeMicros + intdiv(self::MICROS, 2), self::MICROS);
        if ($baseMicros <= 0) {
            return null;
        }
        $numerator = $netAmount * self::SCALE * self::MICROS;

        return intdiv($numerator + intdiv($baseMicros, 2), $baseMicros);
    }

    /**
     * The median of the prices; for an even count the mean of the two in the middle, rounded down.
     *
     * @param list<int> $prices
     */
    public static function median(array $prices): ?int
    {
        $count = \count($prices);
        if (0 === $count) {
            return null;
        }
        sort($prices);
        $middle = intdiv($count, 2);

        return 1 === $count % 2 ? $prices[$middle] : intdiv($prices[$middle - 1] + $prices[$middle], 2);
    }

    /**
     * Whether the price is far from the median of the sample: above MAX_RATIO times it or below it divided
     * by MAX_RATIO (the edges themselves are fine). A sample of fewer than MIN_SAMPLE prices, or a median
     * of 0, says nothing.
     *
     * @param list<int> $sample the product's confirmed prices
     */
    public static function isOutlier(int $price, array $sample): bool
    {
        if (\count($sample) < self::MIN_SAMPLE) {
            return false;
        }
        $median = self::median($sample);
        if (null === $median || $median <= 0) {
            return false;
        }

        return $price > $median * self::MAX_RATIO || $price * self::MAX_RATIO < $median;
    }

    /** A decimal string as millionths, or null when it has more than six decimals or is not a plain decimal. */
    private static function micros(string $value): ?int
    {
        if (1 !== preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/D', $value, $parts)) {
            return null;
        }

        return (int) $parts[1] * self::MICROS + (int) str_pad($parts[2] ?? '', 6, '0');
    }
}
