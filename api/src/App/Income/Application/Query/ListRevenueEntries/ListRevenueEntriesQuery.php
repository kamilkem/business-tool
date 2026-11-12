<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListRevenueEntries;

use App\Shared\Application\Query\QueryInterface;

/**
 * The revenue entries of the current organization (TenantFilter) whose business date is from $from to
 * $to (both inclusive, YYYY-MM-DD; ADR 0017), of all registers or of $registerId only, by business
 * date, then by register name. Entries of deactivated registers are included.
 *
 * Owners and managers only (ADR 0022 §7). Returns null when the actor is not an active member.
 */
final readonly class ListRevenueEntriesQuery implements QueryInterface
{
    /** The longest period one request may cover, in days: a quarter, as for shifts. */
    public const int MAX_DAYS = 92;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $from,
        public string $to,
        public ?string $registerId = null,
    ) {
    }
}
