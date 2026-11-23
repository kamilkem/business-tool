<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/** What a write did to a revenue entry (ADR 0034 §4). */
enum RevenueEntryChangeKind: string
{
    /** Recorded by hand. */
    case Created = 'created';
    /** Changed by hand. */
    case Changed = 'changed';
    /** Deleted by hand, or with its end-of-day close (dayCloseId set). */
    case Deleted = 'deleted';
    /** Written by an end-of-day close: booked new, booked again, or a manual entry taken over. */
    case Booked = 'booked';
}
