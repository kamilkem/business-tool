<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** The person is already clocked in: they have an open shift in this organization. */
final class ShiftAlreadyOpenException extends TimeTrackingException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" is already clocked in. Clock out first.', $userId));
    }
}
