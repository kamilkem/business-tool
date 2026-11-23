<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/**
 * Only the member who asked withdraws a request. Owners and managers, who see everyone's requests,
 * reject them instead; an employee never sees someone else's request (ShiftChangeRequestNotFoundException).
 */
final class ShiftChangeRequestWithdrawalForbiddenException extends TimeTrackingException
{
    public static function forUser(string $requestId, string $userId): self
    {
        return new self(\sprintf('User "%s" may not withdraw shift change request "%s": only the member who asked does.', $userId, $requestId), ['requestId' => $requestId, 'userId' => $userId]);
    }
}
