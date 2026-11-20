<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetLockedShiftPeriod;

use App\TimeTracking\Domain\Model\LockedShiftPeriod;

final readonly class LockedShiftPeriodView
{
    public function __construct(
        public string $id,
        /** The id of what locked the period (a payroll run). */
        public string $lockedBy,
        /** YYYY-MM-DD, inclusive. */
        public string $firstDate,
        /** YYYY-MM-DD, inclusive. */
        public string $lastDate,
        public \DateTimeImmutable $lockedAt,
    ) {
    }

    public static function of(LockedShiftPeriod $period): self
    {
        $dates = $period->getPeriod();

        return new self(
            $period->getId()->toRfc4122(),
            $period->getLockedBy()->toRfc4122(),
            $dates->first->toString(),
            $dates->last->toString(),
            $period->getLockedAt(),
        );
    }
}
