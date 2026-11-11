<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListShifts;

use App\Shared\Application\Query\QueryInterface;

/**
 * Shifts in the current organization that started on the business dates from $from to $to (both
 * inclusive, YYYY-MM-DD, in the organization's time zone; ADR 0017), newest first. A shift belongs to
 * the date it started on, also when it ends after midnight, and open shifts are included.
 *
 * Owners and managers see everyone's shifts, deactivated members' included (ADR 0014), and may narrow
 * them to one person with $userId; employees see their own only. Returns null when the organization
 * does not exist or the actor is not an active member of it.
 */
final readonly class ListShiftsQuery implements QueryInterface
{
    /** The longest period one request may cover, in days. */
    public const int MAX_DAYS = 92;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $from,
        public string $to,
        public ?string $userId = null,
    ) {
    }
}
