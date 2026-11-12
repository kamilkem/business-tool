<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

/**
 * An invoice's totals at one VAT rate, as printed, in minor units of the invoice's currency
 * (ADR 0010). Amounts may be negative (corrections). For an invoice in another currency than the
 * tax currency, the VAT also in the tax currency; null otherwise.
 */
final readonly class PurchaseInvoiceVatAmount
{
    public function __construct(
        public string $rate,
        public int $net,
        public int $vat,
        public ?int $vatInTaxCurrency = null,
    ) {
    }
}
