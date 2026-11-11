<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** A shift must end strictly after it started. */
final class ShiftEndNotAfterStartException extends TimeTrackingException
{
    public static function of(string $shiftId, \DateTimeImmutable $startedAt, \DateTimeImmutable $endedAt): self
    {
        return new self(\sprintf(
            'Shift "%s" cannot end at %s: it started at %s.',
            $shiftId,
            $endedAt->format(\DATE_ATOM),
            $startedAt->format(\DATE_ATOM),
        ));
    }
}
