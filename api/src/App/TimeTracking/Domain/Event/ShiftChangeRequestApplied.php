<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * An owner or manager approving a shift change request had its change applied to the shift, and the
 * shift is saved. The request is approved from here (ApproveShiftChangeRequestCommand): the shift
 * changes first, so a change the shift rules refuse leaves the request pending.
 */
final readonly class ShiftChangeRequestApplied implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $requestId,
        /** The shift entered for a missing shift, or the shift that got the new times. */
        public string $shiftId,
        /** The owner or manager approving the request. */
        public string $approvedById,
    ) {
    }
}
