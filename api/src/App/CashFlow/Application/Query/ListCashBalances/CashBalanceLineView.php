<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListCashBalances;

final readonly class CashBalanceLineView
{
    public function __construct(
        public string $denominationId,
        /** ISO 4217 */
        public string $currency,
        /** Face value in minor units. */
        public int $value,
        /** `coin` or `note`. */
        public string $kind,
        /** False when the denomination is deactivated: a manager takes its pieces out with an adjustment. */
        public bool $active,
        /** May be below zero for a counted storage, whose balance between closes is an estimate. */
        public int $pieces,
        /** pieces × value, in minor units. */
        public int $amount,
    ) {
    }
}
