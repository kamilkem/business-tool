<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductPriceHistory;

use App\Purchasing\Domain\Model\ProductMeasure;

final readonly class ProductPriceHistoryView
{
    /** @param list<PricePointView> $points oldest first */
    public function __construct(
        public string $productId,
        public string $productName,
        public ProductMeasure $measure,
        public ?string $referenceQuantity,
        /** ISO 4217 code of the organization's currency, the only one counted. */
        public string $currency,
        public ?string $from,
        public ?string $to,
        public array $points,
    ) {
    }
}
