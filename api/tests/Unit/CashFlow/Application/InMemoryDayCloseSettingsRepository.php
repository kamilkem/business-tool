<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Domain\Model\DayCloseSettings;
use App\CashFlow\Domain\Repository\DayCloseSettingsRepositoryInterface;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryDayCloseSettingsRepository implements DayCloseSettingsRepositoryInterface
{
    private ?DayCloseSettings $settings = null;

    public int $saves = 0;

    public function findCurrent(): ?DayCloseSettings
    {
        return $this->settings;
    }

    public function add(DayCloseSettings $settings): void
    {
        if (null !== $this->settings) {
            throw new \LogicException('The organization already has day-close settings.');
        }

        $this->settings = $settings;
    }

    public function save(DayCloseSettings $settings): void
    {
        ++$this->saves;
    }
}
