<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Repository;

use App\TimeTracking\Domain\Model\TimeTrackingSettings;

/** The time tracking settings of the current organization only (TenantFilter). */
interface TimeTrackingSettingsRepositoryInterface
{
    /** The current organization's settings; null while it uses the defaults. */
    public function findCurrent(): ?TimeTrackingSettings;

    public function add(TimeTrackingSettings $settings): void;

    /** Persists changes made to already managed settings. */
    public function save(TimeTrackingSettings $settings): void;
}
