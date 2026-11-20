<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\DeleteHourlyRate;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes an hourly rate; its dates fall back to the member's previous rate. */
final readonly class DeleteHourlyRateCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $rateId,
    ) {
    }
}
