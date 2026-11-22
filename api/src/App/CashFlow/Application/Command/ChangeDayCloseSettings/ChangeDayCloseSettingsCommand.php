<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\ChangeDayCloseSettings;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager sets how the organization's end-of-day closes get their date (ADR 0033 §5).
 * `settingsId` is used only when the organization has no settings yet (it used the defaults).
 */
final readonly class ChangeDayCloseSettingsCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $settingsId,
        /** `day_end_time` or `employee_picks`. */
        public string $mode,
        /** HH:MM, from 00:00 to 12:00. */
        public string $dayEndsAt,
    ) {
    }
}
