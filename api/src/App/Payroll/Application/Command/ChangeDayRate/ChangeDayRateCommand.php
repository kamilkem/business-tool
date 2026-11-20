<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ChangeDayRate;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager corrects a day rate's date or amount; its currency becomes the organization's. */
final readonly class ChangeDayRateCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $rateId,
        /** YYYY-MM-DD */
        public string $date,
        /** Gross minor units per hour, in the organization's currency. */
        public int $amount,
    ) {
    }
}
