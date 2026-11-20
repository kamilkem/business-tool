<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\SetHourlyRate;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager gives a member an hourly rate from a business date (ADR 0027 §3). */
final readonly class SetHourlyRateCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $rateId,
        public string $userId,
        /** YYYY-MM-DD */
        public string $effectiveFrom,
        /** Gross minor units per hour, in the organization's currency. */
        public int $amount,
    ) {
    }
}
