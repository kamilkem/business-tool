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
        ), ['shiftId' => $shiftId, 'startedAt' => $startedAt->format(\DATE_ATOM), 'endedAt' => $endedAt->format(\DATE_ATOM)], variant: __FUNCTION__);
    }

    /** For times asked for in a shift change request, before there is a shift with them. */
    public static function requested(\DateTimeImmutable $startedAt, \DateTimeImmutable $endedAt): self
    {
        return new self(\sprintf(
            'A shift cannot end at %s: it would start at %s.',
            $endedAt->format(\DATE_ATOM),
            $startedAt->format(\DATE_ATOM),
        ), ['startedAt' => $startedAt->format(\DATE_ATOM), 'endedAt' => $endedAt->format(\DATE_ATOM)], variant: __FUNCTION__);
    }
}
