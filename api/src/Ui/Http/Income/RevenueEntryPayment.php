<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Domain\Model\PaymentMethod;

/** The gross amount taken with one payment method, in minor units. */
final readonly class RevenueEntryPayment
{
    public function __construct(
        public PaymentMethod $method,
        public int $amount,
    ) {
    }
}
