<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\RegeneratePayrollRun;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager rebuilds a draft's shift lines from the current shifts and rates, keeping its adjustments and, unless both rounding fields are given, its rounding (ADR 0027 §6). */
final readonly class RegeneratePayrollRunCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
        /** A RoundingUnit value, or null to keep the run's. */
        public ?string $roundingUnit = null,
        /** A RoundingDirection value, or null to keep the run's. */
        public ?string $roundingDirection = null,
    ) {
    }
}
