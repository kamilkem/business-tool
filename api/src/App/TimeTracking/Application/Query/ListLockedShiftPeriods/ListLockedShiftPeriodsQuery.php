<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListLockedShiftPeriods;

use App\Shared\Application\Query\QueryInterface;

/**
 * All of the current organization's (TenantFilter) locked shift periods, oldest first: a few a year.
 * Payroll refuses a rate change when any date it affects is locked; an hourly rate affects the dates
 * until the person's next rate, which may be open-ended (#60).
 *
 * For other modules' Application code, not for an API response: the consumer checks its own
 * permissions before asking.
 */
final readonly class ListLockedShiftPeriodsQuery implements QueryInterface
{
}
