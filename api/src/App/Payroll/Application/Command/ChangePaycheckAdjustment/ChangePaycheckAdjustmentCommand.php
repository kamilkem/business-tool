<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ChangePaycheckAdjustment;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager corrects an adjustment on a draft run. */
final readonly class ChangePaycheckAdjustmentCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
        public string $adjustmentId,
        public string $label,
        /** Signed minor units in the run's currency. */
        public int $amount,
    ) {
    }
}
