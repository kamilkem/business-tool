<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\DeletePayrollRun;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a draft payroll run (ADR 0027 §6). */
final readonly class DeletePayrollRunCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
    ) {
    }
}
