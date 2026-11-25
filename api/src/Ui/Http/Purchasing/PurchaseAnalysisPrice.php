<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Analysis\PriceChange;

/** A price per the product's reference quantity (integer minor units) and its change against the product's previous price point (ADR 0041 §7). */
final readonly class PurchaseAnalysisPrice
{
    public function __construct(
        /** Minor units, rounded half away from zero from exact decimals. */
        public int $price,
        /** The difference to the previous price point's price, from any seller; null for the first purchase. */
        public ?int $change,
        /** The difference as a percentage of the previous price, a decimal string with at most 2 decimals (`"-3.45"`); null for the first purchase or when the previous price was 0. */
        public ?string $changePercent,
    ) {
    }

    public static function fromDomain(?PriceChange $price): ?self
    {
        return null === $price ? null : new self($price->price, $price->change, $price->changePercent);
    }
}
