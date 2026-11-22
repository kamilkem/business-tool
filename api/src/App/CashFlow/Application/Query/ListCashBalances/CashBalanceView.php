<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListCashBalances;

final readonly class CashBalanceView
{
    /**
     * @param list<CashBalanceLineView> $lines every active denomination of the organization's currency,
     *                                         then any other one the storage holds; by currency, then value
     */
    public function __construct(
        public string $storageId,
        public string $name,
        /** `counted` or `deposit`. */
        public string $mode,
        public bool $active,
        /** ISO 4217: the organization's currency, which the total is in. */
        public string $currency,
        public array $lines,
        /** The value of the lines in the organization's currency, in minor units. */
        public int $total,
        /** True for a counted storage: between closes its balance does not include the day's takings. */
        public bool $estimate,
        /** The moment of the last count its balance starts from; null when it was never counted. */
        public ?\DateTimeImmutable $countedAt,
    ) {
    }
}
