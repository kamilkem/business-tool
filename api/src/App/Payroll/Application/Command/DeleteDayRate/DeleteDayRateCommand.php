<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\DeleteDayRate;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a day rate; the date falls back to the member's hourly rate. */
final readonly class DeleteDayRateCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $rateId,
    ) {
    }
}
