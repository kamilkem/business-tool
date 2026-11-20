<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * An owner approved a payroll run and it was saved (ADR 0027 §7). Time tracking locks the month's
 * shifts from here (LockShiftsOnPayrollRunApproved, §8).
 */
final readonly class PayrollRunApproved implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $payrollRunId,
        /** The month's first business date, YYYY-MM-DD. */
        public string $firstDate,
        /** The month's last business date, YYYY-MM-DD. */
        public string $lastDate,
        public string $approvedById,
    ) {
    }
}
