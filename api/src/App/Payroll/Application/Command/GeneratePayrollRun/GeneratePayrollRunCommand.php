<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\GeneratePayrollRun;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager generates a draft payroll run of a month from its ended shifts and the rates in effect (ADR 0027 §6). */
final readonly class GeneratePayrollRunCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
        /** YYYY-MM, in the organization's time zone. */
        public string $month,
        /** A RoundingUnit value: none, 15, 30 or 60. */
        public string $roundingUnit,
        /** A RoundingDirection value: nearest, up or down. */
        public string $roundingDirection,
    ) {
    }
}
