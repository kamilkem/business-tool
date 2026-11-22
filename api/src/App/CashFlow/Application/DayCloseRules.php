<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\Model\DayCloseRule;
use App\CashFlow\Domain\Repository\DayCloseSettingsRepositoryInterface;

/**
 * The current organization's rule for the dates of end-of-day closes (ADR 0033 §5): its
 * DayCloseSettings, or the defaults when it has none. The close (#185) resolves its date with
 * `$rules->current()->dateOfClose($picked, $actor->role, $clock->now(), new \DateTimeZone($actor->organization->timezone))`.
 */
final readonly class DayCloseRules
{
    public function __construct(
        private DayCloseSettingsRepositoryInterface $settings,
    ) {
    }

    public function current(): DayCloseRule
    {
        return $this->settings->findCurrent()?->rule() ?? DayCloseRule::default();
    }
}
