<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Repository;

use App\CashFlow\Domain\Model\DayCloseSettings;

/** The day-close settings of the current organization only (TenantFilter). */
interface DayCloseSettingsRepositoryInterface
{
    /** The current organization's settings; null while it uses the defaults. */
    public function findCurrent(): ?DayCloseSettings;

    public function add(DayCloseSettings $settings): void;

    /** Persists changes made to already managed settings. */
    public function save(DayCloseSettings $settings): void;
}
