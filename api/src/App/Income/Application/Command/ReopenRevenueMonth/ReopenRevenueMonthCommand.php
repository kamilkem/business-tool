<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ReopenRevenueMonth;

use App\Shared\Application\Command\CommandInterface;

/** An owner reopens a closed Income month (ADR 0034 §1), which unlocks its entries. */
final readonly class ReopenRevenueMonthCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        /** YYYY-MM */
        public string $month,
    ) {
    }
}
