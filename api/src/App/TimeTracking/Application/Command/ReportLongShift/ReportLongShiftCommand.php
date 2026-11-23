<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ReportLongShift;

use App\Shared\Application\Command\CommandInterface;

/**
 * Reports an open shift that runs longer than the organization's maximum shift length (#33): sent by
 * ReportLongShiftsJobHandler, never from Ui, with no actor. Idempotent: a shift that is closed,
 * within the limit or already reported is left as it is and nobody is e-mailed again.
 */
final readonly class ReportLongShiftCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $shiftId,
    ) {
    }
}
