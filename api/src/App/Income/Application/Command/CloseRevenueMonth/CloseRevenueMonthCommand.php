<?php

declare(strict_types=1);

namespace App\Income\Application\Command\CloseRevenueMonth;

use App\Shared\Application\Command\CommandInterface;

/** An owner closes an Income month (ADR 0034 §1); $revenueMonthId is used when the month is closed for the first time. */
final readonly class CloseRevenueMonthCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        /** YYYY-MM */
        public string $month,
        public string $revenueMonthId,
    ) {
    }
}
