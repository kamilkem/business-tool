<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Purchasing\Domain\Model\PackageSize;
use App\Purchasing\Domain\Model\ProductMeasure;

/**
 * What the rules read from an item's name and unit (ItemReader). `packageSize` is base units (L, kg)
 * per one unit of the item's quantity, a decimal string with at most 6 decimals, null when the text
 * has none or says two things. A *strong* reading may lead to `assigned`; a weak one (a multipack,
 * which a seller may count in bottles instead of packs, or a size on a unit of unknown count) only to
 * `suggested`, unless the seller's multiplier for the unit is known (ADR 0042 §5, ADR 0043).
 *
 * `packageSize` is always the total per unit of the quantity, which every comparison uses. A multipack
 * (`6x0,5L`) and a seller's multiplier read it as a bundle too (ADR 0048): `bundleCount` single packages of
 * `singleSize` each, whose product is `packageSize`; both are null for anything that is no bundle.
 */
final readonly class ItemReading
{
    public function __construct(
        public ?string $packageSize,
        /** Volume or mass when a size was read, null otherwise. */
        public ?ProductMeasure $measure,
        public SizeSource $source,
        public bool $strong,
        /** The unit is one single piece (`szt.`, `but.`, `puszka`) or there is none. */
        public bool $pieceUnit,
        /**
         * What the unit counts is known: a measure (`l`, `kg`), a single piece or none, or the size is
         * in the unit's text. A case, a carton, a pack (`CO`, `kart.`, `op.`) or any other unit holds an
         * unknown number of pieces, so a size read from the name is not per unit of the quantity (ADR 0043).
         */
        public bool $unitCountKnown,
        /** Single packages per unit of the quantity, 2 or more, when the text or the seller's multiplier says so (ADR 0048). */
        public ?int $bundleCount = null,
        /** The size of one single package of the bundle; null when there is no bundle. */
        public ?string $singleSize = null,
    ) {
    }

    /**
     * The package a person's confirmation of the item with a product of this measure starts from
     * (ADR 0044): only what the text states strongly and in the product's measure, or one piece for a
     * product counted in pieces with a piece unit and no size. Null when the text does not say.
     */
    public function packageFor(ProductMeasure $measure): ?PackageSize
    {
        if (!$measure->hasUnit()) {
            return null;
        }
        if ($this->hasSize()) {
            return $this->strong && $this->measure === $measure ? $this->size() : null;
        }

        return ProductMeasure::Count === $measure && $this->pieceUnit && SizeSource::Ambiguous !== $this->source ? PackageSize::of('1') : null;
    }

    /** What the text says, as a bundle when it says so; null when it gives no size. */
    public function size(): ?PackageSize
    {
        if (null === $this->packageSize) {
            return null;
        }

        return PackageSize::of($this->singleSize ?? $this->packageSize, null === $this->singleSize ? null : $this->bundleCount);
    }

    public function hasSize(): bool
    {
        return null !== $this->packageSize;
    }
}
