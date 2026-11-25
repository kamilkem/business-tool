<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceItemView;

/**
 * One item of an imported expense, as printed and never changed (ADR 0040). Amounts are integer minor
 * units of the expense's currency; quantities, unit prices and the discount are decimal strings with
 * trailing zeros trimmed (`"0.7"`, `"12.34567891"`), never to be added up as floats. Negative in
 * corrections.
 */
final readonly class ExpenseItem
{
    public function __construct(
        /** The row number printed on the invoice; items are ordered by it. Not unique. */
        public int $position,
        public string $name,
        /** The seller's own product code. */
        public ?string $sellerCode,
        /** The Global Trade Item Number (EAN), when printed. */
        public ?string $gtin,
        /** The unit as printed (`szt.`, `l`, `kg`). */
        public ?string $unit,
        /** Decimal string. */
        public ?string $quantity,
        /** Decimal string in the expense's currency. */
        public ?string $unitNetPrice,
        /** Decimal string in the expense's currency. */
        public ?string $unitGrossPrice,
        /** Decimal string in the expense's currency. */
        public ?string $discount,
        /** Minor units; an invoice gives the net or the gross value, or both. */
        public ?int $netAmount,
        public ?int $grossAmount,
        public ?int $vatAmount,
        /** A rate code of the organization's country (GET /vat-rates); null when the printed rate has none. */
        public ?string $vatRate,
        /** In a correction: the row as it was before the correction. */
        public bool $beforeCorrection,
    ) {
    }

    public static function fromView(PurchaseInvoiceItemView $view): self
    {
        return new self(
            $view->position,
            $view->name,
            $view->sellerCode,
            $view->gtin,
            $view->unit,
            $view->quantity,
            $view->unitNetPrice,
            $view->unitGrossPrice,
            $view->discount,
            $view->netAmount,
            $view->grossAmount,
            $view->vatAmount,
            $view->vatRate,
            $view->beforeCorrection,
        );
    }
}
