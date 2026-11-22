<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseSettings;

final readonly class DayCloseSettingsView
{
    public function __construct(
        /** `day_end_time` or `employee_picks`. */
        public string $mode,
        /** HH:MM in the organization's time zone. */
        public string $dayEndsAt,
        /** When an owner or manager changed them last; null while the organization uses the defaults. */
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }
}
