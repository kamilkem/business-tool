<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoicesWithItems;

use App\Expenses\Domain\Event\PurchaseInvoiceItemsRecorded;
use App\Expenses\Domain\Model\PurchaseInvoice;

/**
 * An invoice with its items as PurchaseInvoiceItemsRecorded carries them: what Purchasing copies
 * (ADR 0041 §2). Primitives only; items by index, quantities and prices as decimal strings (ADR 0040).
 *
 * @phpstan-import-type RecordedItem from PurchaseInvoiceItemsRecorded
 */
final readonly class PurchaseInvoiceWithItemsView
{
    /** @param list<RecordedItem> $items by index */
    public function __construct(
        public string $invoiceId,
        /** Y-m-d. */
        public string $issueDate,
        /** A PurchaseInvoiceKind value. */
        public string $kind,
        /** ISO 4217: the invoice's currency. */
        public string $currency,
        public ?string $sellerTaxId,
        public string $sellerName,
        public array $items,
    ) {
    }

    public static function fromInvoice(string $organizationId, PurchaseInvoice $invoice): self
    {
        $recorded = PurchaseInvoiceItemsRecorded::ofInvoice($organizationId, $invoice);

        return new self($recorded->invoiceId, $recorded->issueDate, $recorded->kind, $recorded->currency, $recorded->sellerTaxId, $recorded->sellerName, $recorded->items);
    }
}
