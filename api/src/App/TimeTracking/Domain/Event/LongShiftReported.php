<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * An open shift ran longer than the organization's maximum shift length and is now marked as
 * reported (#33): the person and the active owners and managers are told by e-mail, once per shift.
 */
final readonly class LongShiftReported implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $shiftId,
        public string $userId,
        /** The maximum shift length in hours when it was reported. */
        public int $maxShiftHours,
    ) {
    }
}
