<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\EventHandler;

use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;

/**
 * What the shift change request e-mails say about a request: primitives only (ADR 0012), times in
 * the organization's time zone (ADR 0017), never the server's.
 */
final class ShiftChangeRequestEmail
{
    /** e.g. "Thu 12 Nov 2026, 17:00" */
    private const string TIME_FORMAT = 'D j M Y, H:i';

    /**
     * @param ?Shift $shift the shift the request is about, if there is one
     *
     * @return array{kind: string, startedAt: string, endedAt: ?string, shiftStartedAt: ?string, shiftEndedAt: ?string, note: string, timeZone: string}
     */
    public static function context(ShiftChangeRequest $request, ?Shift $shift, string $timeZone): array
    {
        $zone = new \DateTimeZone($timeZone);

        return [
            'kind' => $request->getKind()->value,
            'startedAt' => self::format($request->getStartedAt(), $zone),
            'endedAt' => null === $request->getEndedAt() ? null : self::format($request->getEndedAt(), $zone),
            'shiftStartedAt' => null === $shift ? null : self::format($shift->getStartedAt(), $zone),
            'shiftEndedAt' => null === $shift?->getEndedAt() ? null : self::format($shift->getEndedAt(), $zone),
            'note' => $request->getNote(),
            'timeZone' => $timeZone,
        ];
    }

    private static function format(\DateTimeImmutable $time, \DateTimeZone $zone): string
    {
        return $time->setTimezone($zone)->format(self::TIME_FORMAT);
    }
}
