<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetPurchasedItem;

use App\Purchasing\Domain\Matching\MatchCaveat;
use App\Purchasing\Domain\Matching\MatchSource;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;

/** A purchased item as the review queue and the product detail show it: what was copied, the link and the invoice number. */
final readonly class PurchasedItemView
{
    public function __construct(
        public string $id,
        public string $invoiceId,
        /** From Expenses, which may correct it; null for an invoice without one. */
        public ?string $invoiceNumber,
        /** The invoice's issue date, `Y-m-d`. */
        public string $invoiceIssueDate,
        public string $invoiceKind,
        public string $currency,
        public ?string $sellerTaxId,
        public string $sellerName,
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
        public ?string $productId,
        public ?string $productName,
        public PurchasedItemStatus $status,
        public ?string $packageSize,
        /** What matching linked it by; null when a person did, or it was matched before this was kept (ADR 0045 §1). */
        public ?MatchSource $matchSource,
        /** Why matching only suggested the product; null otherwise (ADR 0045 §1). */
        public ?MatchCaveat $matchCaveat,
    ) {
    }

    public static function fromItem(PurchasedItem $item, ?string $invoiceNumber): self
    {
        return new self(
            $item->getId()->toRfc4122(),
            $item->getInvoiceId()->toRfc4122(),
            $invoiceNumber,
            $item->getInvoiceIssueDate()->toString(),
            $item->getInvoiceKind(),
            $item->getCurrency(),
            $item->getSellerTaxId(),
            $item->getSellerName(),
            $item->getPosition(),
            $item->getName(),
            $item->getSellerCode(),
            $item->getGtin(),
            $item->getUnit(),
            $item->getQuantity(),
            $item->getUnitNetPrice(),
            $item->getUnitGrossPrice(),
            $item->getDiscount(),
            $item->getNetAmount(),
            $item->getGrossAmount(),
            $item->getVatAmount(),
            $item->getVatRate(),
            $item->isBeforeCorrection(),
            $item->getProduct()?->getId()->toRfc4122(),
            $item->getProduct()?->getName(),
            $item->getStatus(),
            $item->getPackageSize(),
            $item->getMatchSource(),
            $item->getMatchCaveat(),
        );
    }
}
