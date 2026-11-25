<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoice;

use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;

/**
 * One item of an invoice as printed (ADR 0040): amounts in minor units of the invoice's currency,
 * quantities, unit prices and the discount as decimal strings with trailing zeros trimmed.
 */
final readonly class PurchaseInvoiceItemView
{
    public function __construct(
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
    }

    public static function fromDetails(PurchaseInvoiceItemDetails $item): self
    {
        return new self(
            $item->position,
            $item->name,
            $item->sellerCode,
            $item->gtin,
            $item->unit,
            $item->quantity,
            $item->unitNetPrice,
            $item->unitGrossPrice,
            $item->discount,
            $item->netAmount,
            $item->grossAmount,
            $item->vatAmount,
            $item->vatRate,
            $item->beforeCorrection,
        );
    }
}
