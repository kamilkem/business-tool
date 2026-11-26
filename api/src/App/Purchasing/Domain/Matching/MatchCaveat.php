<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/**
 * Why a matched item is only `suggested` and not `assigned` (ADR 0045 §1): the first rule of ADR 0042 §6
 * (and ADR 0045 §2, the price check) that held it back. An `assigned` item has none.
 */
enum MatchCaveat: string
{
    /** The item's aliases point to different products; the strongest kind's product is suggested. */
    case AliasesConflict = 'aliases_conflict';
    /** The model said `medium`. */
    case ModelUnsure = 'model_unsure';
    /** The model proposed a name a product has: it contradicted itself, so a person decides. */
    case ProposedNameOnly = 'proposed_name_only';
    /** The size read from the item is of another measure than the product (litres for a mass product). */
    case MeasureMismatch = 'measure_mismatch';
    /** No package size is known for a product that has a measure. */
    case SizeUnknown = 'size_unknown';
    /** Only the item's own text says the size and only weakly (a multipack), and no alias or model confirms it. */
    case SizeReadWeakly = 'size_read_weakly';
    /** The alias or the model remembers another size than the item's text. */
    case SizeDisagrees = 'size_disagrees';
    /** The size agrees but sits on a unit of unknown count (`CO`, `kart.`), so it proves nothing (ADR 0043). */
    case SizeUnverified = 'size_unverified';
    /** The price per base unit is far from the product's median (ADR 0045 §2): a wrong multipack reading? */
    case PriceOutlier = 'price_outlier';
}
