<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

/** A person works one shift at a time: their shifts in an organization never overlap. */
final class ShiftOverlapException extends TimeTrackingException
{
    public static function with(string $otherShiftId, \DateTimeImmutable $otherStartedAt, ?\DateTimeImmutable $otherEndedAt): self
    {
        return new self(\sprintf(
            'The shift would overlap the person\'s shift "%s" (%s to %s).',
            $otherShiftId,
            $otherStartedAt->format(\DATE_ATOM),
            $otherEndedAt?->format(\DATE_ATOM) ?? 'now: it is still open',
        ), ['otherShiftId' => $otherShiftId, 'otherStartedAt' => $otherStartedAt->format(\DATE_ATOM), 'otherEndedAt' => $otherEndedAt?->format(\DATE_ATOM)]);
    }
}
