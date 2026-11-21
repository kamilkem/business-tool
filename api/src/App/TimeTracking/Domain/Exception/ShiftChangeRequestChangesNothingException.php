<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** A request for new times must ask for times other than the shift's own. */
final class ShiftChangeRequestChangesNothingException extends TimeTrackingException
{
    public static function forShift(string $shiftId): self
    {
        return new self(\sprintf('The requested times are already shift "%s"\'s times.', $shiftId), ['shiftId' => $shiftId]);
    }
}
