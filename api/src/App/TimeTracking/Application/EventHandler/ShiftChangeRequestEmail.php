<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\EventHandler;

use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;

/**
 * What the shift change request e-mails say about a request: primitives only (ADR 0012), times in
 * the organization's time zone (ADR 0017), never the server's, with day and month names in the
 * recipient's language (ADR 0028 §6).
 */
final class ShiftChangeRequestEmail
{
    /** ICU pattern: "Thu 12 Nov 2026, 17:00" in English, "czw. 12 lis 2026, 17:00" in Polish. */
    private const string TIME_PATTERN = 'EEE d MMM y, HH:mm';

    /**
     * @param ?Shift $shift  the shift the request is about, if there is one
     * @param string $locale the recipient's language code
     *
     * @return array{kind: string, startedAt: string, endedAt: ?string, shiftStartedAt: ?string, shiftEndedAt: ?string, note: string, timeZone: string}
     */
    public static function context(ShiftChangeRequest $request, ?Shift $shift, string $timeZone, string $locale): array
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timeZone, \IntlDateFormatter::GREGORIAN, self::TIME_PATTERN);

        return [
            'kind' => $request->getKind()->value,
            'startedAt' => self::format($formatter, $request->getStartedAt()),
            'endedAt' => null === $request->getEndedAt() ? null : self::format($formatter, $request->getEndedAt()),
            'shiftStartedAt' => null === $shift ? null : self::format($formatter, $shift->getStartedAt()),
            'shiftEndedAt' => null === $shift?->getEndedAt() ? null : self::format($formatter, $shift->getEndedAt()),
            'note' => $request->getNote(),
            'timeZone' => $timeZone,
        ];
    }

    private static function format(\IntlDateFormatter $formatter, \DateTimeImmutable $time): string
    {
        $formatted = $formatter->format($time);
        if (false === $formatted) {
            throw new \RuntimeException($formatter->getErrorMessage());
        }

        return $formatted;
    }
}
