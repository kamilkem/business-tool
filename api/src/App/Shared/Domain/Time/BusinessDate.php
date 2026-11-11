<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;

/**
 * A calendar date with no time and no time zone: "the 25th of October" for a business.
 *
 * Which instants belong to it depends on the organization's time zone (ADR 0002 §4), so every
 * conversion to or from a timestamp takes the zone explicitly (ADR 0017). Timestamps stay in UTC
 * (ADR 0010); "today" is `BusinessDate::containing($clock->now(), $timeZone)` (ADR 0015).
 */
final readonly class BusinessDate implements \Stringable
{
    private function __construct(
        public int $year,
        public int $month,
        public int $day,
    ) {
    }

    /** @throws InvalidBusinessDateException when the date does not exist (e.g. 2026-02-30) */
    public static function of(int $year, int $month, int $day): self
    {
        if ($year < 1 || $year > 9999 || !checkdate($month, $day, $year)) {
            throw InvalidBusinessDateException::notACalendarDate($year, $month, $day);
        }

        return new self($year, $month, $day);
    }

    /**
     * Parses a strict YYYY-MM-DD string, as sent in API query strings.
     *
     * @throws InvalidBusinessDateException when the format is wrong or the date does not exist
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
            throw InvalidBusinessDateException::notYmd($value);
        }

        return self::of((int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    /** The business date on which the given instant falls in the given time zone. */
    public static function containing(\DateTimeImmutable $instant, \DateTimeZone $timeZone): self
    {
        $local = $instant->setTimezone($timeZone);

        return new self((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
    }

    /**
     * The first instant of this date in the given time zone, in UTC.
     *
     * Usually local midnight; where the clocks jump over midnight (e.g. America/Santiago), the
     * first local time that exists.
     */
    public function startsAt(\DateTimeZone $timeZone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->toString().' 00:00:00', $timeZone)->setTimezone(new \DateTimeZone('UTC'));
    }

    /** [start of this date, start of the next date) in UTC; 23 or 25 hours long on DST days. */
    public function toUtcRange(\DateTimeZone $timeZone): UtcRange
    {
        return new UtcRange($this->startsAt($timeZone), $this->next()->startsAt($timeZone));
    }

    public function next(): self
    {
        return self::fromCalendarDay($this->calendarDay()->add(new \DateInterval('P1D')));
    }

    public function previous(): self
    {
        return self::fromCalendarDay($this->calendarDay()->sub(new \DateInterval('P1D')));
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function isBefore(self $other): bool
    {
        return $this->toString() < $other->toString();
    }

    public function isAfter(self $other): bool
    {
        return $this->toString() > $other->toString();
    }

    /** YYYY-MM-DD */
    public function toString(): string
    {
        return \sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** This date as midnight UTC, only for calendar arithmetic (UTC has no DST). */
    private function calendarDay(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->toString(), new \DateTimeZone('UTC'));
    }

    private static function fromCalendarDay(\DateTimeImmutable $day): self
    {
        return self::of((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
    }
}
