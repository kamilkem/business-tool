<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\AddPaycheckAdjustment;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager adds a bonus (+) or a deduction (−) to a member's paycheck on a draft run (ADR 0027 §6). */
final readonly class AddPaycheckAdjustmentCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
        public string $adjustmentId,
        /** The member, active or deactivated; their paycheck is created if they have no shifts that month. */
        public string $userId,
        public string $label,
        /** Signed minor units in the run's currency. */
        public int $amount,
    ) {
    }
}
