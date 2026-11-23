<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** Only owners and managers change the organization's time tracking settings (#33). */
final class TimeTrackingSettingsChangeForbiddenException extends TimeTrackingException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not change the time tracking settings: only owners and managers do.', $userId), ['userId' => $userId]);
    }
}
