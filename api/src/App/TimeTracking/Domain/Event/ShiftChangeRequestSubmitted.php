<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/** A member submitted a shift change request, and it is saved; owners and managers are told by e-mail. */
final readonly class ShiftChangeRequestSubmitted implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $requestId,
        public string $requesterId,
    ) {
    }
}
