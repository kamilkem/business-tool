<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\LockShiftPeriod;

use App\Shared\Application\Command\CommandInterface;

/**
 * Locks the shifts of the business dates from $firstDate to $lastDate (both inclusive, YYYY-MM-DD)
 * in the current organization, on behalf of $lockedBy (a payroll run, ADR 0027 §8). Sent by
 * LockShiftsOnPayrollRunApproved once Payroll publishes PayrollRunApproved (#62); there is no HTTP
 * endpoint. Who may approve a run is Payroll's rule, so this command has no actor.
 *
 * Idempotent: locking again for the same $lockedBy and period changes nothing.
 */
final readonly class LockShiftPeriodCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        /** The new lock's id, used only when the period is not locked for $lockedBy yet. */
        public string $lockedShiftPeriodId,
        public string $lockedBy,
        public string $firstDate,
        public string $lastDate,
    ) {
    }
}
