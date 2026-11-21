<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** The shift has already ended; its end time does not change by clocking out again. */
final class ShiftAlreadyClosedException extends TimeTrackingException
{
    public static function of(string $shiftId): self
    {
        return new self(\sprintf('Shift "%s" has already ended.', $shiftId), ['shiftId' => $shiftId]);
    }
}
