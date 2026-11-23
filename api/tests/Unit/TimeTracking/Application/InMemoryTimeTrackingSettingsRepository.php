<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\TimeTracking\Domain\Model\TimeTrackingSettings;
use App\TimeTracking\Domain\Repository\TimeTrackingSettingsRepositoryInterface;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryTimeTrackingSettingsRepository implements TimeTrackingSettingsRepositoryInterface
{
    private ?TimeTrackingSettings $settings = null;

    public int $saves = 0;

    public function findCurrent(): ?TimeTrackingSettings
    {
        return $this->settings;
    }

    public function add(TimeTrackingSettings $settings): void
    {
        if (null !== $this->settings) {
            throw new \LogicException('The organization already has time tracking settings.');
        }

        $this->settings = $settings;
    }

    public function save(TimeTrackingSettings $settings): void
    {
        ++$this->saves;
    }
}
