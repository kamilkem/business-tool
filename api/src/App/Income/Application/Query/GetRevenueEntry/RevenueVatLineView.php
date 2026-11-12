<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueEntry;

final readonly class RevenueVatLineView
{
    public function __construct(
        public string $rate,
        public int $gross,
        public int $vat,
        /** gross − VAT. */
        public int $net,
    ) {
    }
}
