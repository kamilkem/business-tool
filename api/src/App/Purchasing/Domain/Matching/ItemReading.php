<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Purchasing\Domain\Model\ProductMeasure;

/**
 * What the rules read from an item's name and unit (ItemReader). `packageSize` is base units (L, kg)
 * per one unit of the item's quantity, a decimal string with at most 6 decimals, null when the text
 * has none or says two things. A *strong* reading may lead to `assigned`; a weak one (a multipack,
 * which a seller may count in bottles instead of packs) only to `suggested` (ADR 0042 §5).
 */
final readonly class ItemReading
{
    public function __construct(
        public ?string $packageSize,
        /** Volume or mass when a size was read, null otherwise. */
        public ?ProductMeasure $measure,
        public SizeSource $source,
        public bool $strong,
        /** The unit is one of a piece or a pack (`szt.`, `but.`, `kart.`) or there is none. */
        public bool $pieceUnit,
    ) {
    }

    public function hasSize(): bool
    {
        return null !== $this->packageSize;
    }
}
