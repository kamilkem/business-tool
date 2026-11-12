<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueSummary;

use App\Shared\Application\Query\QueryInterface;

/**
 * The totals of the current organization's revenue entries (TenantFilter) whose business date is from
 * $from to $to (both inclusive, YYYY-MM-DD; ADR 0017), per currency: per payment method, per VAT rate,
 * per register and overall, with the number of days that have entries (ADR 0022, Consequences).
 * Also asked by other modules through the query bus (the monthly business summary, #67).
 *
 * Owners and managers only (ADR 0022 §7). Returns null when the actor is not an active member.
 */
final readonly class GetRevenueSummaryQuery implements QueryInterface
{
    /** The longest period one request may cover, in days: a year, leap years included. */
    public const int MAX_DAYS = 366;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $from,
        public string $to,
    ) {
    }
}
