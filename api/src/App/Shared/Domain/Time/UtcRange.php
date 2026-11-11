<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

/**
 * A half-open range of instants [from, to) in UTC: `from` belongs to it, `to` does not.
 *
 * It is what a business date or period becomes for a query on UTC timestamps
 * (`started_at >= :from AND started_at < :to`), so consecutive days and periods never overlap.
 */
final readonly class UtcRange
{
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        if ($to <= $from) {
            throw new \InvalidArgumentException(\sprintf('A range must end after it starts: [%s, %s).', $from->format(\DATE_ATOM), $to->format(\DATE_ATOM)));
        }

        $utc = new \DateTimeZone('UTC');
        $this->from = $from->setTimezone($utc);
        $this->to = $to->setTimezone($utc);
    }

    public function contains(\DateTimeImmutable $instant): bool
    {
        return $instant >= $this->from && $instant < $this->to;
    }

    /** Length in seconds; a business day is 23 or 25 hours long when the clocks change. */
    public function durationInSeconds(): int
    {
        return $this->to->getTimestamp() - $this->from->getTimestamp();
    }
}
