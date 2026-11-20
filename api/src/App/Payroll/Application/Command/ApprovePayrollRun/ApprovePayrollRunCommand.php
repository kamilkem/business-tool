<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ApprovePayrollRun;

use App\Shared\Application\Command\CommandInterface;

/** An owner approves a draft payroll run, which locks the month's shifts (ADR 0027 §7–8). */
final readonly class ApprovePayrollRunCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
    ) {
    }
}
