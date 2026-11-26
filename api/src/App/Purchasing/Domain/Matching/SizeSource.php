<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/** Where ItemReader found an item's package size (ADR 0042 §5). */
enum SizeSource: string
{
    /** The unit is a measure itself (`l`, `kg`): the quantity already is in base units. */
    case Unit = 'unit';
    /** One size in the name (`0,7L`). */
    case Name = 'name';
    /** A multipack in the name (`6x0,5L`): the size is its total. */
    case Multipack = 'multipack';
    /** A size in the unit's text (`op. 0,2l`), the name has none. */
    case UnitText = 'unit_text';
    /** Two different sizes in the name. */
    case Ambiguous = 'ambiguous';
    case None = 'none';
}
