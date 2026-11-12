<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueEntry;

final readonly class RevenuePaymentLineView
{
    public function __construct(
        /** A PaymentMethod value. */
        public string $method,
        public int $amount,
    ) {
    }
}
