<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/**
 * A shift with a pending change request about it is not deleted: an owner or manager decides the
 * request first, so the person who asked hears back (#28).
 */
final class ShiftHasPendingChangeRequestException extends TimeTrackingException
{
    public static function of(string $shiftId, string $requestId): self
    {
        return new self(
            \sprintf('Shift "%s" has a pending change request "%s"; decide it before deleting the shift.', $shiftId, $requestId),
            ['shiftId' => $shiftId, 'requestId' => $requestId],
        );
    }
}
