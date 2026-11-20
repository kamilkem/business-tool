<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoices;

use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;

final readonly class PurchaseInvoiceListView
{
    /**
     * @param list<PurchaseInvoiceView>              $items  newest issue date first
     * @param list<PurchaseInvoiceCurrencyTotalView> $totals the organization's currency first, always present; others by code
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $items,
        public array $totals,
    ) {
    }
}
