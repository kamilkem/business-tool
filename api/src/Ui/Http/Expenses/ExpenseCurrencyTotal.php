<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Application\Query\ListPurchaseInvoices\PurchaseInvoiceCurrencyTotalView;

/** The listed expenses of one currency added up, in its minor units; corrections count with their negative amounts. */
final readonly class ExpenseCurrencyTotal
{
    /** @param list<ExpenseVatLine> $vatLines per VAT rate used, highest rate first, exempt last */
    public function __construct(
        /** ISO 4217 code. */
        public string $currency,
        public int $invoiceCount,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        public array $vatLines,
    ) {
    }

    public static function fromView(PurchaseInvoiceCurrencyTotalView $view): self
    {
        return new self(
            $view->currency,
            $view->invoiceCount,
            $view->netTotal,
            $view->vatTotal,
            $view->grossTotal,
            array_map(ExpenseVatLine::fromView(...), $view->vatLines),
        );
    }
}
