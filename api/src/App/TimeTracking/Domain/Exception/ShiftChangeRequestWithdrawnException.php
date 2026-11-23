<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** The requester withdrew the request: it is no longer decided, nor withdrawn again. */
final class ShiftChangeRequestWithdrawnException extends TimeTrackingException
{
    public static function of(string $requestId): self
    {
        return new self(\sprintf('Shift change request "%s" was withdrawn.', $requestId), ['requestId' => $requestId]);
    }
}
