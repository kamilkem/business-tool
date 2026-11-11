<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** Shifts are recorded after they happened: an owner or manager may not enter a time after now. */
final class ShiftInTheFutureException extends TimeTrackingException
{
    public static function of(\DateTimeImmutable $time, \DateTimeImmutable $now): self
    {
        return new self(\sprintf(
            'A shift cannot start or end in the future: %s is after now (%s).',
            $time->format(\DATE_ATOM),
            $now->format(\DATE_ATOM),
        ));
    }
}
