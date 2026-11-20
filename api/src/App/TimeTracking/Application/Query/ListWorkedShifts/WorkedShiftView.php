<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListWorkedShifts;

use App\Shared\Domain\Time\BusinessDate;
use App\TimeTracking\Domain\Model\Shift;

final readonly class WorkedShiftView
{
    public function __construct(
        public string $shiftId,
        public string $userId,
        /** The business date the shift belongs to: the local date it started on (YYYY-MM-DD). */
        public string $date,
        public \DateTimeImmutable $startedAt,
        /** Null while the shift is open. */
        public ?\DateTimeImmutable $endedAt,
        /**
         * The real time between start and end, unrounded, so a night across a DST change is an hour
         * shorter or longer than the clock suggests; null while the shift is open.
         */
        public ?int $seconds,
    ) {
    }

    public static function of(Shift $shift, \DateTimeZone $timeZone): self
    {
        $startedAt = $shift->getStartedAt();
        $endedAt = $shift->getEndedAt();

        return new self(
            $shift->getId()->toRfc4122(),
            $shift->getUserId()->toRfc4122(),
            BusinessDate::containing($startedAt, $timeZone)->toString(),
            $startedAt,
            $endedAt,
            null === $endedAt ? null : $endedAt->getTimestamp() - $startedAt->getTimestamp(),
        );
    }
}
