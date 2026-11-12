<?php

declare(strict_types=1);

namespace Ui\Http\Income;

/** What one register took in the period, in the currency it is listed under. */
final readonly class RevenueSummaryRegister
{
    public function __construct(
        public string $registerId,
        public string $registerName,
        /** The sum of the register's entries' payment lines, in minor units. */
        public int $total,
        /** The register's entries in the period: one per day at most. */
        public int $entryCount,
    ) {
    }
}
