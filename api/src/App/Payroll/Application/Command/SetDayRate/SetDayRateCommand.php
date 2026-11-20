<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\SetDayRate;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager gives a member a rate for one business date (ADR 0027 §3). */
final readonly class SetDayRateCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $rateId,
        public string $userId,
        /** YYYY-MM-DD */
        public string $date,
        /** Gross minor units per hour, in the organization's currency. */
        public int $amount,
    ) {
    }
}
