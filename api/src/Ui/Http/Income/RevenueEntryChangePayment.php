<?php

declare(strict_types=1);

namespace Ui\Http\Income;

/** A payment line as it was, with the method's name at the time. */
final readonly class RevenueEntryChangePayment
{
    public function __construct(
        public string $paymentMethodId,
        public string $paymentMethodName,
        /** Minor units. */
        public int $amount,
    ) {
    }
}
