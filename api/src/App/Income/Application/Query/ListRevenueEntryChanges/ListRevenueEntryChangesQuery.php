<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListRevenueEntryChanges;

use App\Shared\Application\Query\QueryInterface;

/**
 * The change history of revenue entries of the current organization (ADR 0034 §4), newest first:
 * one entry's ($entryId, also after it was deleted) or that of the entries dated from $from to $to
 * (YYYY-MM-DD, both inclusive, at most MAX_DAYS). Owners and managers only; null when the actor is
 * not an active member.
 */
final readonly class ListRevenueEntryChangesQuery implements QueryInterface
{
    public const int MAX_DAYS = 92;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        public ?string $entryId = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {
    }
}
