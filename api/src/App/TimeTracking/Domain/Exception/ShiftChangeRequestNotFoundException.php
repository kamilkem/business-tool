<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** No such request in this organization, or not one the person may see (an employee sees their own only). */
final class ShiftChangeRequestNotFoundException extends TimeTrackingException
{
    public static function withId(string $requestId): self
    {
        return new self(\sprintf('Shift change request "%s" not found.', $requestId), ['requestId' => $requestId]);
    }
}
