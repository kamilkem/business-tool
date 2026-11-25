<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/**
 * What Purchasing copies of an invoice item: the invoice's fields an analysis needs and the item as
 * printed (ADR 0041 §3, ADR 0040), as Expenses stored them. Amounts are minor units of the invoice's
 * currency and may be negative; quantities, unit prices and the discount are decimal strings, never
 * floats and never added up in PHP.
 */
final readonly class PurchasedItemDetails
{
    /**
     * @param int     $itemIndex        the item's place in the document, from 1; with the invoice's id it names the item for good
     * @param string  $invoiceKind      a PurchaseInvoiceKind value; kept as text, as Purchasing only compares it
     * @param string  $currency         ISO 4217: the invoice's currency
     * @param int     $position         the row number printed on the invoice; not unique
     * @param ?string $sellerCode       the seller's own product code
     * @param ?string $unit             the unit as printed (`szt.`, `l`, `kg`)
     * @param ?string $vatRate          a VatRates code of the organization's country
     * @param bool    $beforeCorrection in a correction, the row as it was before the correction
     */
    public function __construct(
        public Uuid $invoiceId,
        public int $itemIndex,
        public BusinessDate $invoiceIssueDate,
        public string $invoiceKind,
        public string $currency,
        public ?string $sellerTaxId,
        public string $sellerName,
        public int $position,
        public string $name,
        public ?string $sellerCode,
        public ?string $gtin,
        public ?string $unit,
        public ?string $quantity,
        public ?string $unitNetPrice,
        public ?string $unitGrossPrice,
        public ?string $discount,
        public ?int $netAmount,
        public ?int $grossAmount,
        public ?int $vatAmount,
        public ?string $vatRate,
        public bool $beforeCorrection,
    ) {
        if ($itemIndex < 1) {
            throw new \InvalidArgumentException(\sprintf('An item index counts from 1, "%d" given.', $itemIndex));
        }
    }
}
