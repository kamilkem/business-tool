<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\InvalidDayEndTimeException;

/**
 * The local time, from 00:00 to 12:00 in the organization's time zone, before which an end-of-day
 * close belongs to the previous date (ADR 0033 §5): at 05:00, a bar closing at 02:30 on Saturday
 * closes Friday. It is a wall-clock time, so it means the same on a DST day.
 */
final readonly class DayEndTime implements \Stringable
{
    public const string DEFAULT = '05:00';

    /** The latest day-end time: a close after noon always belongs to its own date. */
    public const string LATEST = '12:00';

    private function __construct(
        public int $hour,
        public int $minute,
    ) {
    }

    /**
     * Parses a strict HH:MM string.
     *
     * @throws InvalidDayEndTimeException when it is not HH:MM or is after 12:00
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $value, $parts) || $value > self::LATEST) {
            throw InvalidDayEndTimeException::of($value, self::LATEST);
        }

        return new self((int) $parts[1], (int) $parts[2]);
    }

    public static function default(): self
    {
        return self::fromString(self::DEFAULT);
    }

    /** Whether this time is after the instant's local wall-clock time in the time zone (to the second). */
    public function isAfterTheLocalTimeOf(\DateTimeImmutable $instant, \DateTimeZone $timeZone): bool
    {
        return $instant->setTimezone($timeZone)->format('H:i:s') < $this->toString().':00';
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    /** HH:MM */
    public function toString(): string
    {
        return \sprintf('%02d:%02d', $this->hour, $this->minute);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
