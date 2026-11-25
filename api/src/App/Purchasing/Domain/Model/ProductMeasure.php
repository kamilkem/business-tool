<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

/**
 * What a product is counted in (ADR 0041 §4). The base unit is the one package sizes and reference
 * quantities are written in: litres, kilograms or pieces. `none` is for what is not goods (rent,
 * electricity, deposits): it counts in spend and has no quantity, price or reference quantity.
 */
enum ProductMeasure: string
{
    /** Base unit: L. */
    case Volume = 'volume';
    /** Base unit: kg. */
    case Mass = 'mass';
    /** Base unit: pc. */
    case Count = 'count';
    case None = 'none';

    /** Whether the product has quantities, prices and a reference quantity. */
    public function hasUnit(): bool
    {
        return self::None !== $this;
    }
}
