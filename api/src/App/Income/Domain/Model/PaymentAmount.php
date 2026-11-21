<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/**
 * The gross amount taken with one of the organization's payment methods, in minor units of the
 * entry's currency (ADR 0010). RevenueEntry checks the amounts and the methods when it takes them.
 */
final readonly class PaymentAmount
{
    public function __construct(
        public PaymentMethod $method,
        public int $amount,
    ) {
    }
}
