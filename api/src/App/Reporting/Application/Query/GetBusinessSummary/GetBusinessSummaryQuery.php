<?php

declare(strict_types=1);

namespace App\Reporting\Application\Query\GetBusinessSummary;

use App\Shared\Application\Query\QueryInterface;

/**
 * The business figures of the organization for each calendar month from $from to $to (both
 * inclusive, YYYY-MM, in the organization's time zone; ADR 0017): income, expenses, payroll and the
 * result, per currency (ADR 0029). The current month is a preview; a later one is refused.
 *
 * Owners and managers only. Returns null when the actor is not an active member.
 */
final readonly class GetBusinessSummaryQuery implements QueryInterface
{
    /** The most months one request may cover: a year, enough for the dashboard's trend (ADR 0029 §2). */
    public const int MAX_MONTHS = 12;

    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $from,
        public string $to,
    ) {
    }
}
