<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueMonth;

use App\Shared\Application\Query\QueryInterface;

/**
 * An Income month of the current organization (ADR 0034): open or closed, whether it has ended, and
 * every close and reopen. A month never closed is open with no actions. Owners and managers only;
 * null when the actor is not an active member.
 */
final readonly class GetRevenueMonthQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        /** YYYY-MM */
        public string $month,
    ) {
    }
}
