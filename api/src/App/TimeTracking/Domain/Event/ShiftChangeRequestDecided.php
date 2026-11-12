<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/** A shift change request was approved or rejected, and it is saved; the requester is told by e-mail. */
final readonly class ShiftChangeRequestDecided implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $requestId,
        public string $requesterId,
        /** The owner or manager who decided it. */
        public string $decidedById,
        /** ShiftChangeRequestStatus: `approved` or `rejected`. */
        public string $status,
    ) {
    }
}
