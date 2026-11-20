<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\UnlockShiftPeriod;

use App\Shared\Application\Command\CommandInterface;

/**
 * Removes the lock that $lockedBy (a payroll run) put on its period in the current organization, so
 * its shifts can change again (a reopened run, ADR 0027 §9). Sent by UnlockShiftsOnPayrollRunReopened
 * once Payroll publishes PayrollRunReopened (#62); there is no HTTP endpoint and no actor.
 *
 * Idempotent: unlocking what is not locked changes nothing.
 */
final readonly class UnlockShiftPeriodCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $lockedBy,
    ) {
    }
}
