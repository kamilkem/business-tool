<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Application\Query\GetProductPriceHistory\PricePointView;

/** One purchase of a product with a unit: where it came from, what was bought, and its price per reference quantity. */
final readonly class ProductPricePoint
{
    public function __construct(
        /** The purchased item (GET /purchased-items/{id}). */
        public string $itemId,
        /** The invoice's issue date, `YYYY-MM-DD`. */
        public string $date,
        public ?string $sellerTaxId,
        public string $sellerName,
        /** Expenses' invoice the item is on. */
        public string $invoiceId,
        /** The invoice's number as Expenses has it now; null for an invoice without one (a receipt). */
        public ?string $invoiceNumber,
        /** The item as printed. */
        public string $itemName,
        /** The item's quantity as printed, a decimal string. */
        public string $quantity,
        /** In the product's base unit per unit of the quantity: a decimal string. */
        public string $packageSize,
        /** The net price per reference quantity; null when the item has no net value to derive. */
        public ?PurchaseAnalysisPrice $net,
        public ?PurchaseAnalysisPrice $gross,
    ) {
    }

    public static function fromView(PricePointView $view): self
    {
        $point = $view->point;

        return new self(
            $point->itemId,
            $point->date,
            $point->sellerTaxId,
            $point->sellerName,
            $point->invoiceId,
            $view->invoiceNumber,
            $point->itemName,
            $point->quantity,
            $point->packageSize,
            PurchaseAnalysisPrice::fromDomain($point->net),
            PurchaseAnalysisPrice::fromDomain($point->gross),
        );
    }
}
