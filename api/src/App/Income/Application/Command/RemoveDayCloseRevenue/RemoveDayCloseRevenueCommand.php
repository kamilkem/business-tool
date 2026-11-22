<?php

declare(strict_types=1);

namespace App\Income\Application\Command\RemoveDayCloseRevenue;

use App\Shared\Application\Command\CommandInterface;

/**
 * Removes the revenue entry booked from a deleted Cash flow end-of-day close (ADR 0033 §9). Sent
 * only by Income's handler of DayCloseDeleted, never by a Ui processor: the authority is the deletion
 * Cash flow accepted, so it is neither checked with IncomeAccess nor refused like a manual delete of a
 * booked entry. The organization must be the current tenant.
 */
final readonly class RemoveDayCloseRevenueCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        /** The owner or manager who deleted the close. */
        public string $actorId,
        public string $dayCloseId,
    ) {
    }
}
