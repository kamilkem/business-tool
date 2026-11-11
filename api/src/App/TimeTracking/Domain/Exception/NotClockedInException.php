<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** The person has no open shift in this organization, so there is nothing to clock out of. */
final class NotClockedInException extends TimeTrackingException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" is not clocked in. Clock in first.', $userId));
    }
}
