<?php

declare(strict_types=1);

namespace App\TimeTracking\Application;

use App\TimeTracking\Domain\Model\MaxShiftLength;
use App\TimeTracking\Domain\Repository\TimeTrackingSettingsRepositoryInterface;

/** The current organization's maximum shift length (#33): its TimeTrackingSettings, or the default. */
final readonly class MaxShiftLengths
{
    public function __construct(
        private TimeTrackingSettingsRepositoryInterface $settings,
    ) {
    }

    public function current(): MaxShiftLength
    {
        return $this->settings->findCurrent()?->maxShiftLength() ?? MaxShiftLength::default();
    }
}
