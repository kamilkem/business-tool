<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueSummary;

/** What one register took in a period, in one currency: the sum of its entries' payment lines. */
final readonly class RevenueRegisterTotalView
{
    public function __construct(
        public string $registerId,
        public string $registerName,
        public int $total,
        /** The register's entries in the period: one per day at most (ADR 0022 §3). */
        public int $entryCount,
    ) {
    }
}
