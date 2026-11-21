<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** Only owners and managers approve or reject shift change requests (ADR 0016 §5). */
final class ShiftChangeRequestDecisionForbiddenException extends TimeTrackingException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not approve or reject shift change requests: only owners and managers do.', $userId), ['userId' => $userId]);
    }
}
