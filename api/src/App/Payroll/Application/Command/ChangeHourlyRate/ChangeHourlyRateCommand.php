<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ChangeHourlyRate;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager corrects an hourly rate's date or amount; its currency becomes the organization's. */
final readonly class ChangeHourlyRateCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $rateId,
        /** YYYY-MM-DD */
        public string $effectiveFrom,
        /** Gross minor units per hour, in the organization's currency. */
        public int $amount,
    ) {
    }
}
