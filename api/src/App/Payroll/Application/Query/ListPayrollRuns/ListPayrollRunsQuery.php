<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListPayrollRuns;

use App\Shared\Application\Query\QueryInterface;

/**
 * The payroll runs the actor may see, newest month first, as summaries: every run for owners and
 * managers; the approved runs with a paycheck of theirs for an employee, with that paycheck's totals.
 * Null when the actor is not an active member.
 */
final readonly class ListPayrollRunsQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
