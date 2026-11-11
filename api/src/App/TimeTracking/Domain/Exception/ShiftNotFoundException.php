<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** No such shift in this organization, or not one the person may see or change. */
final class ShiftNotFoundException extends TimeTrackingException
{
    public static function withId(string $shiftId): self
    {
        return new self(\sprintf('Shift "%s" not found.', $shiftId));
    }

    public static function currentOf(string $userId): self
    {
        return new self(\sprintf('User "%s" is not clocked in: there is no current shift.', $userId));
    }
}
