<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayClose;

final readonly class DayClosePaymentView
{
    public function __construct(
        public string $paymentMethodId,
        /** The method's current name; null when Income no longer offers it to this reader. */
        public ?string $name,
        /** Minor units. */
        public int $amount,
    ) {
    }
}
