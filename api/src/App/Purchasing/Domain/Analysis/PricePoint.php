<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Analysis;

/**
 * One price point (ADR 0041 §7): a purchased item of a product with a unit, with its prices per the
 * product's reference quantity, in minor units rounded half away from zero (half up for a positive
 * value) from SQL NUMERIC, and its change against the product's previous price point on each basis.
 * A price is null when the item has no net (or gross) value to derive; a change is null without a
 * previous price point on that basis, and the percentage also when the previous price was 0.
 */
final readonly class PricePoint
{
    public function __construct(
        public string $itemId,
        public string $date,
        public ?string $sellerTaxId,
        public string $sellerName,
        public string $invoiceId,
        public string $itemName,
        /** The item's quantity as printed, a decimal string. */
        public string $quantity,
        /** The item's package size in the product's base unit, a decimal string. */
        public string $packageSize,
        public ?PriceChange $net,
        public ?PriceChange $gross,
    ) {
    }
}
