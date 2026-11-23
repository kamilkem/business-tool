<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListOrganizationsWithLongShifts;

use App\Shared\Application\Query\QueryInterface;

/**
 * The ids of the organizations, of all of them, that may have an open shift to report as too long
 * at `now` (#33): one not yet reported that is older than the shortest maximum shift length. Each
 * organization's own limit is applied by ReportLongShiftsJob. For the scheduled fan-out only.
 */
final readonly class ListOrganizationsWithLongShiftsQuery implements QueryInterface
{
    public function __construct(
        /** ISO 8601. */
        public string $now,
    ) {
    }
}
