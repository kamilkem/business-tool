<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListWorkedShifts;

use App\Shared\Application\Query\QueryInterface;

/**
 * The shifts of the current organization (TenantFilter) that count for the business dates from $from
 * to $to (both inclusive, YYYY-MM-DD) in $timeZone, the organization's, and the pending shift change
 * requests about them: what a payroll run is built from (ADR 0027 §5–7).
 *
 * A shift belongs to the business date it started on and is never split at midnight or at the end of
 * the period. Open shifts are included, and so are the shifts of deactivated members (ADR 0014): the
 * query does not look at memberships. $userId narrows everything to one person.
 *
 * For other modules' Application code (Payroll), not for an API response: the consumer checks its
 * own permissions before asking.
 */
final readonly class ListWorkedShiftsQuery implements QueryInterface
{
    public function __construct(
        public string $from,
        public string $to,
        /** The organization's time zone (an IANA name such as `Europe/Warsaw`). */
        public string $timeZone,
        public ?string $userId = null,
    ) {
    }
}
