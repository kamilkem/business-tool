<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** Only owners and managers add and correct shifts; employees ask for a change instead (ADR 0016 §5). */
final class ShiftChangeForbiddenException extends TimeTrackingException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not add or change shifts: only owners and managers do.', $userId));
    }
}
