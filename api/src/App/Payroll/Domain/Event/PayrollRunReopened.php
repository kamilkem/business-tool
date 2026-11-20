<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * An owner reopened an approved payroll run back to draft and it was saved (ADR 0027 §9). Time
 * tracking unlocks the month's shifts from here (UnlockShiftsOnPayrollRunReopened).
 */
final readonly class PayrollRunReopened implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $payrollRunId,
        public string $reopenedById,
    ) {
    }
}
