<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\RemovePaycheckAdjustment;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager removes an adjustment from a draft run. */
final readonly class RemovePaycheckAdjustmentCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $payrollRunId,
        public string $adjustmentId,
    ) {
    }
}
