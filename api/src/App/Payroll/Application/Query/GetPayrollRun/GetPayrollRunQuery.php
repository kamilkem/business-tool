<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayrollRun;

use App\Shared\Application\Query\QueryInterface;

/**
 * One payroll run as the actor may see it (ADR 0027 §10): owners and managers see any run with every
 * paycheck; an employee sees an approved run with their own paycheck only. Null when the run is not
 * the organization's, or not visible to the actor.
 */
final readonly class GetPayrollRunQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
    ) {
    }
}
