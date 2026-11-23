<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetTimeTrackingSettings;

final readonly class TimeTrackingSettingsView
{
    public function __construct(
        /** Whole hours a shift may run before it is flagged as a forgotten clock-out. */
        public int $maxShiftHours,
        /** When an owner or manager changed them last; null while the organization uses the defaults. */
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }
}
