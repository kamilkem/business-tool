<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Analysis;

use App\Purchasing\Domain\Model\ProductMeasure;

/**
 * What a product cost in a period and, when it has a unit, how much was bought at what price. The
 * quantity and the prices come from its price points only (ADR 0041 §7); the spend counts every
 * item, corrections included.
 */
final readonly class ProductSpend
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $categoryId,
        public ProductMeasure $measure,
        public ?string $referenceQuantity,
        public AnalysisAmounts $spend,
        /** Σ quantity × package size ÷ reference quantity of the price points, a decimal string with at most 3 decimals; null without any. */
        public ?string $quantity,
        /** Σ amount ÷ Σ quantity × package size × reference quantity per basis, minor units; null without a price point with that basis. */
        public ?int $averageNetPrice,
        public ?int $averageGrossPrice,
        /** The period's last price point (by issue date, then when it was stored); null without any. */
        public ?string $lastPriceDate,
        public ?PriceChange $lastNet,
        public ?PriceChange $lastGross,
    ) {
    }
}
