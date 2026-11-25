<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

/**
 * One item of a purchase invoice as an importer gives it; the aggregate checks it when it takes it
 * (PurchaseInvoice::import(), ADR 0040). Amounts are minor units of the invoice's currency and may be
 * negative (corrections). Quantities, unit prices and the discount are decimal strings (`0.7`,
 * `12.34567891`, `-2`), never floats.
 */
final readonly class PurchaseInvoiceItemDetails
{
    /**
     * @param int     $position         the row number printed on the invoice; items are ordered by it
     * @param ?string $sellerCode       the seller's own product code
     * @param ?string $unit             the unit as printed (`szt.`, `l`, `kg`)
     * @param ?string $quantity         decimal string, negative in corrections
     * @param ?string $unitNetPrice     decimal string in the invoice's currency
     * @param ?string $unitGrossPrice   decimal string in the invoice's currency
     * @param ?string $discount         decimal string in the invoice's currency
     * @param ?int    $netAmount        minor units; an invoice gives the net or the gross value, or both
     * @param ?string $vatRate          a VatRates code of the organization's country; null when the printed rate has none
     * @param bool    $beforeCorrection in a correction, the row as it was before the correction
     */
    public function __construct(
        public int $position,
        public string $name,
        public ?string $sellerCode = null,
        public ?string $gtin = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $unitNetPrice = null,
        public ?string $unitGrossPrice = null,
        public ?string $discount = null,
        public ?int $netAmount = null,
        public ?int $grossAmount = null,
        public ?int $vatAmount = null,
        public ?string $vatRate = null,
        public bool $beforeCorrection = false,
    ) {
    }
}
