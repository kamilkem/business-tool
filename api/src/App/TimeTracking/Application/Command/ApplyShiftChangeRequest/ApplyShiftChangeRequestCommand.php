<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ApplyShiftChangeRequest;

use App\Shared\Application\Command\CommandInterface;

/**
 * The first step of approving a shift change request: an owner or manager applies its change to the
 * shift. It changes the Shift aggregate only; the request is approved after it, from the
 * ShiftChangeRequestApplied event (ApproveShiftChangeRequestCommand). The organization must be the
 * current tenant (TenantContextInterface).
 */
final readonly class ApplyShiftChangeRequestCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $requestId,
        /** The id for the shift a missing-shift request enters (ADR 0009); unused for new times. */
        public string $newShiftId,
    ) {
    }
}
