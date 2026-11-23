<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueMonth;

final readonly class RevenueMonthView
{
    /** @param list<RevenueMonthActionView> $actions oldest first */
    public function __construct(
        /** YYYY-MM */
        public string $month,
        public string $firstDate,
        public string $lastDate,
        public bool $ended,
        public bool $closed,
        public ?string $closedBy,
        public ?\DateTimeImmutable $closedAt,
        public array $actions,
    ) {
    }
}
