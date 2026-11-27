<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\PackageSize;

/** What the repository counts of one product's items: those printed with one unit that have one package size, and whether a person confirmed them (ADR 0048). */
final readonly class PackageSizeCount
{
    public function __construct(
        /** The unit as the seller printed it; null for none. */
        public ?string $unit,
        /** A decimal string; null: the item has none. */
        public ?string $packageSize,
        public bool $confirmed,
        public int $count,
    ) {
    }
}
