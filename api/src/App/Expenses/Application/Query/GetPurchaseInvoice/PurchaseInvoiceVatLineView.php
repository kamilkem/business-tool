<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoice;

use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;

/** One VAT rate of an invoice, or of the invoices of one currency added up, in minor units. */
final readonly class PurchaseInvoiceVatLineView
{
    public function __construct(
        public string $rate,
        public int $net,
        public int $vat,
        /** The VAT in the tax currency, for an invoice in another currency; null otherwise. */
        public ?int $vatInTaxCurrency,
    ) {
    }

    public static function fromAmount(PurchaseInvoiceVatAmount $amount): self
    {
        return new self($amount->rate, $amount->net, $amount->vat, $amount->vatInTaxCurrency);
    }
}
