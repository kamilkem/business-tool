<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayClose;

final readonly class DayCloseLineView
{
    public function __construct(
        public string $denominationId,
        /** Face value in minor units. */
        public int $value,
        public string $kind,
        /** Counted (what stays in a counted storage) or deposited. */
        public int $pieces,
        /** $pieces × $value, in minor units. */
        public int $amount,
    ) {
    }
}
