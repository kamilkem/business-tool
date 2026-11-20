<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\UndoPaycheckPaid;

use App\Shared\Application\Command\CommandInterface;

/** An owner undoes the paid mark of a paycheck (ADR 0027 §9). */
final readonly class UndoPaycheckPaidCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
        public string $paycheckId,
    ) {
    }
}
