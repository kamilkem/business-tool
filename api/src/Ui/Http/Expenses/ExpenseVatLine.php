<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceVatLineView;

/** One VAT rate of an expense, or of the expenses of one currency added up, in minor units; negative for corrections. */
final readonly class ExpenseVatLine
{
    public function __construct(
        /** A rate code of the organization's country (GET /vat-rates): "23", "zw". */
        public string $rate,
        public int $net,
        public int $vat,
        /** The VAT in the tax currency, for an invoice in another currency; null otherwise. */
        public ?int $vatInTaxCurrency,
    ) {
    }

    public static function fromView(PurchaseInvoiceVatLineView $view): self
    {
        return new self($view->rate, $view->net, $view->vat, $view->vatInTaxCurrency);
    }
}
