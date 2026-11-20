<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ReopenPayrollRun;

use App\Shared\Application\Command\CommandInterface;

/** An owner reopens an approved payroll run back to draft, which unlocks the month's shifts (ADR 0027 §9). */
final readonly class ReopenPayrollRunCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
    ) {
    }
}
