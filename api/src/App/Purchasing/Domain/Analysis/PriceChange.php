<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Analysis;

/** A price per reference quantity (minor units) and its change against the previous price point. */
final readonly class PriceChange
{
    public function __construct(
        public int $price,
        /** The difference to the previous price, minor units; null without a previous price point. */
        public ?int $change,
        /** The difference as a percentage of the previous price, a decimal string with at most 2 decimals; null without a previous price or when it was 0. */
        public ?string $changePercent,
    ) {
    }
}
