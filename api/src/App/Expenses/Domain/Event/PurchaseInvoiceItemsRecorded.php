<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Event;

use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Shared\Domain\Event\EventInterface;

/**
 * An invoice got its items, and that is saved: it was imported with them, or its importer read them
 * again from the stored document (#230, ADR 0041 §2). It is Expenses' first domain event; Purchasing
 * keeps its own record of each item (ADR 0033 §9). It carries primitives only: the invoice's fields
 * that an analysis needs and every item as stored, with its index, the item's place in the document
 * (from 1), which with the invoice's id names it for good (printed positions may repeat, ADR 0040).
 *
 * @phpstan-type RecordedItem array{index: int, position: int, name: string, sellerCode: ?string, gtin: ?string, unit: ?string, quantity: ?string, unitNetPrice: ?string, unitGrossPrice: ?string, discount: ?string, netAmount: ?int, grossAmount: ?int, vatAmount: ?int, vatRate: ?string, beforeCorrection: bool}
 */
final readonly class PurchaseInvoiceItemsRecorded implements EventInterface
{
    /** @param list<RecordedItem> $items by index */
    public function __construct(
        public string $organizationId,
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

    /** The event for the items the invoice stores now; the invoice has at least one. */
    public static function ofInvoice(string $organizationId, PurchaseInvoice $invoice): self
    {
        $items = [];
        foreach ($invoice->getItemsByIndex() as $index => $item) {
            $items[] = [
                'index' => $index,
                'position' => $item->position,
                'name' => $item->name,
                'sellerCode' => $item->sellerCode,
                'gtin' => $item->gtin,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'unitNetPrice' => $item->unitNetPrice,
                'unitGrossPrice' => $item->unitGrossPrice,
                'discount' => $item->discount,
                'netAmount' => $item->netAmount,
                'grossAmount' => $item->grossAmount,
                'vatAmount' => $item->vatAmount,
                'vatRate' => $item->vatRate,
                'beforeCorrection' => $item->beforeCorrection,
            ];
        }

        return new self(
            $organizationId,
            $invoice->getId()->toRfc4122(),
            $invoice->getIssueDate()->toString(),
            $invoice->getKind()->value,
            $invoice->getCurrency(),
            $invoice->getSellerTaxId(),
            $invoice->getSellerName(),
            $items,
        );
    }
}
