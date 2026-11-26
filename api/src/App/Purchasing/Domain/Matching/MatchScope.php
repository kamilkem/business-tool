<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/** Which matchable items a matching run goes through (ADR 0042 §7). A `confirmed` item is in none of them. */
enum MatchScope: string
{
    /** Items the model was never asked about: new ones, and the history once. */
    case NotYetMatched = 'not_yet_matched';
    /** Every `unassigned` item, also those the model found nothing for before: the catalog may have grown. */
    case Unassigned = 'unassigned';
    /** Every item matching may change: `unassigned`, `suggested` and `assigned`. */
    case AllMatchable = 'all_matchable';
}
