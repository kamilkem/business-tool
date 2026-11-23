<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

use App\TimeTracking\Domain\Exception\InvalidMaxShiftLengthException;

/**
 * How long a shift may run before it counts as a forgotten clock-out (#33): whole hours, per
 * organization (TimeTrackingSettings), 12 by default. A shift longer than this is only flagged and
 * reported (the person and the owners and managers are e-mailed once while it is open); nothing ends
 * it, and the next clock-in is still refused until it ends.
 */
final readonly class MaxShiftLength
{
    public const int DEFAULT_HOURS = 12;
    public const int MIN_HOURS = 1;
    public const int MAX_HOURS = 24;

    private function __construct(public int $hours)
    {
    }

    /** @throws InvalidMaxShiftLengthException when the hours are outside MIN_HOURS to MAX_HOURS */
    public static function ofHours(int $hours): self
    {
        if ($hours < self::MIN_HOURS || $hours > self::MAX_HOURS) {
            throw InvalidMaxShiftLengthException::of($hours, self::MIN_HOURS, self::MAX_HOURS);
        }

        return new self($hours);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_HOURS);
    }

    /** Whether a shift from $startedAt to $endedAt (or to now, while open) runs longer than this. */
    public function isExceededBy(\DateTimeImmutable $startedAt, \DateTimeImmutable $endedAt): bool
    {
        return $endedAt->getTimestamp() - $startedAt->getTimestamp() > $this->hours * 3600;
    }

    /** Shifts that started before this moment run longer than this at $now. */
    public function startedBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d hours', $this->hours));
    }

    public function equals(self $other): bool
    {
        return $this->hours === $other->hours;
    }
}
