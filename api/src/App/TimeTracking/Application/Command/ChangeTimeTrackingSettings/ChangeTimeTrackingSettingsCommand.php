<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ChangeTimeTrackingSettings;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager sets the organization's time tracking settings (#33). `settingsId` is used
 * only when the organization has no settings yet (it used the defaults).
 */
final readonly class ChangeTimeTrackingSettingsCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $settingsId,
        /** Whole hours, from MaxShiftLength::MIN_HOURS to MAX_HOURS. */
        public int $maxShiftHours,
    ) {
    }
}
