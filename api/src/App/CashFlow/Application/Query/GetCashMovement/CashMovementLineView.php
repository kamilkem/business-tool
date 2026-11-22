<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetCashMovement;

final readonly class CashMovementLineView
{
    public function __construct(
        public string $denominationId,
        /** Face value in minor units of the movement's currency. */
        public int $value,
        /** `coin` or `note`. */
        public string $kind,
        /** Signed for an adjustment, moved for a transfer, counted for a count. */
        public int $pieces,
        /** pieces × value, in minor units. */
        public int $amount,
    ) {
    }
}
