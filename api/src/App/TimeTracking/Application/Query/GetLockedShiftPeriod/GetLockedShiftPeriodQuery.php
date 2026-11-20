<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetLockedShiftPeriod;

use App\Shared\Application\Query\QueryInterface;

/**
 * The current organization's (TenantFilter) locked shift period that contains the business date
 * $date (YYYY-MM-DD), or null when the date is open: Payroll refuses rates and day rates for a locked
 * date (ADR 0027 §3, #60).
 *
 * For other modules' Application code, not for an API response: the consumer checks its own
 * permissions before asking.
 */
final readonly class GetLockedShiftPeriodQuery implements QueryInterface
{
    public function __construct(
        public string $date,
    ) {
    }
}
