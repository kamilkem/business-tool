<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

/**
 * How a purchased item is linked to a product (ADR 0041 §3). It starts `unassigned`; matching (#233)
 * and the owner move it on, and a re-run of matching never changes a `confirmed` link.
 */
enum PurchasedItemStatus: string
{
    case Unassigned = 'unassigned';
    /** Matched to a product by matching, for a person to confirm. */
    case Suggested = 'suggested';
    /** Matched to a product by matching, confidently. */
    case Assigned = 'assigned';
    /** Linked by a person. */
    case Confirmed = 'confirmed';
}
