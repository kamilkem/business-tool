<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ApproveShiftChangeRequest;

use App\Shared\Application\Command\CommandInterface;

/**
 * Marks a shift change request approved after its change was applied to the shift
 * (ApplyShiftChangeRequestCommand, then the ShiftChangeRequestApplied event). Sent by
 * ApproveRequestOnShiftChangeRequestApplied, not by clients. The organization must be the current
 * tenant (TenantContextInterface).
 */
final readonly class ApproveShiftChangeRequestCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $requestId,
        /** The shift the change was applied to. */
        public string $shiftId,
    ) {
    }
}
