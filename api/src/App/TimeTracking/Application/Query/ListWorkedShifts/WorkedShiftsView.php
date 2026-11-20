<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListWorkedShifts;

final readonly class WorkedShiftsView
{
    public function __construct(
        /** @var list<WorkedShiftView> oldest first (by start, then by id) */
        public array $shifts,
        /**
         * Pending shift change requests that would change a shift of the period: new times for a
         * shift that started in it or would start in it, or a missing shift asked to start in it.
         */
        public int $pendingChangeRequests,
    ) {
    }
}
