<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ExportPayrollRun;

use App\Shared\Application\Query\QueryInterface;

/**
 * An approved payroll run with what the accountant needs (ADR 0027 §12): each paycheck with the
 * person's name, and each shift line with the shift's start and end. Owners and managers only
 * (PayrollAccessForbiddenException); a draft is a PayrollRunStatusException. Null when the run is not
 * the organization's.
 */
final readonly class ExportPayrollRunQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
    ) {
    }
}
