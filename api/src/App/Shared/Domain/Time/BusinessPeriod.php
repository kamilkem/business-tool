<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;

/**
 * An inclusive range of business dates: "1–30 November", "this pay period", "a single day".
 *
 * Like BusinessDate it has no time zone; `toUtcRange()` turns it into the [from, to) instants
 * to query UTC timestamps with (ADR 0017).
 */
final readonly class BusinessPeriod
{
    private function __construct(
        public BusinessDate $first,
        public BusinessDate $last,
    ) {
    }

    /** @throws InvalidBusinessPeriodException when $last is before $first */
    public static function fromDates(BusinessDate $first, BusinessDate $last): self
    {
        if ($last->isBefore($first)) {
            throw InvalidBusinessPeriodException::endsBeforeItStarts($first, $last);
        }

        return new self($first, $last);
    }

    /**
     * Parses two YYYY-MM-DD strings, as sent in API query strings (`?from=…&to=…`), both inclusive.
     *
     * @throws InvalidBusinessDateException   when either string is not a date
     * @throws InvalidBusinessPeriodException when $last is before $first
     */
    public static function fromStrings(string $first, string $last): self
    {
        return self::fromDates(BusinessDate::fromString($first), BusinessDate::fromString($last));
    }

    public static function day(BusinessDate $date): self
    {
        return new self($date, $date);
    }

    /** @throws InvalidBusinessPeriodException when the month does not exist */
    public static function month(int $year, int $month): self
    {
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12) {
            throw InvalidBusinessPeriodException::notAMonth($year, $month);
        }

        $daysInMonth = (int) new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC'))->format('t');

        return new self(BusinessDate::of($year, $month, 1), BusinessDate::of($year, $month, $daysInMonth));
    }

    /** The calendar month the date falls in. */
    public static function monthOf(BusinessDate $date): self
    {
        return self::month($date->year, $date->month);
    }

    public function contains(BusinessDate $date): bool
    {
        return !$date->isBefore($this->first) && !$date->isAfter($this->last);
    }

    /** Number of calendar days, both ends included. */
    public function dayCount(): int
    {
        $utc = new \DateTimeZone('UTC');
        $first = new \DateTimeImmutable($this->first->toString(), $utc);
        $last = new \DateTimeImmutable($this->last->toString(), $utc);

        return intdiv($last->getTimestamp() - $first->getTimestamp(), 86400) + 1;
    }

    /** [start of the first date, start of the day after the last date) in UTC. */
    public function toUtcRange(\DateTimeZone $timeZone): UtcRange
    {
        return new UtcRange($this->first->startsAt($timeZone), $this->last->next()->startsAt($timeZone));
    }

    public function equals(self $other): bool
    {
        return $this->first->equals($other->first) && $this->last->equals($other->last);
    }
}
