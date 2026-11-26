<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/** The interpreter's answer for one item: the catalog product it is, or none and then a proposed product, and the package size it read. */
final readonly class ItemInterpretation
{
    public function __construct(
        /** The ItemToInterpret key. */
        public string $key,
        /** A CatalogProduct id; null when no product of the catalog is it. */
        public ?string $productId,
        public InterpretationConfidence $confidence,
        /** Base units (L, kg, pieces) per one unit of the item's quantity, a positive decimal string; null when unknown. */
        public ?string $packageSize,
        /** What to create when no catalog product is it (ADR 0044); only ever used for an item that is not matched. */
        public ?ProductProposal $proposal = null,
    ) {
    }
}
