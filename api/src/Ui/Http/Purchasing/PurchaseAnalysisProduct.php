<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Analysis\ProductSpend;

/**
 * A product with items in the period: its spend, and for a product with a unit the quantity bought
 * and its prices, all from its price points (ADR 0041 §7). Prices are per `referenceQuantity` of the
 * product's base unit (L, kg or pc).
 */
final readonly class PurchaseAnalysisProduct
{
    public function __construct(
        public string $id,
        public string $name,
        /** The product's category; null under "Uncategorized". */
        public ?string $categoryId,
        /** `volume` (L), `mass` (kg), `count` (pc) or `none` (spend only). */
        public string $measure,
        /** A decimal string in the base unit; null when the measure is `none`. */
        public ?string $referenceQuantity,
        /** Every item of the period counts, corrections included. */
        public PurchaseAnalysisAmounts $spend,
        /** Bought in the period, in reference quantities: a decimal string with at most 3 decimals; null without a price point. */
        public ?string $quantity,
        /** Minor units per reference quantity: Σ amount ÷ Σ quantity bought; null without a price point on that basis. */
        public ?int $averageNetPrice,
        public ?int $averageGrossPrice,
        /** The issue date of the period's last price point, `YYYY-MM-DD`; null without one. */
        public ?string $lastPriceDate,
        /** The last price point's net price and its change against the previous one, which may be before the period; null when it has no net value. */
        public ?PurchaseAnalysisPrice $lastNetPrice,
        public ?PurchaseAnalysisPrice $lastGrossPrice,
    ) {
    }

    public static function fromDomain(ProductSpend $product): self
    {
        return new self(
            $product->id,
            $product->name,
            $product->categoryId,
            $product->measure->value,
            $product->referenceQuantity,
            PurchaseAnalysisAmounts::fromDomain($product->spend),
            $product->quantity,
            $product->averageNetPrice,
            $product->averageGrossPrice,
            $product->lastPriceDate,
            PurchaseAnalysisPrice::fromDomain($product->lastNet),
            PurchaseAnalysisPrice::fromDomain($product->lastGross),
        );
    }
}
