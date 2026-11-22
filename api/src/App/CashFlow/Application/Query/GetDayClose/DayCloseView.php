<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayClose;

final readonly class DayCloseView
{
    /**
     * @param list<DayCloseStorageView> $storages in the order of the storages' names
     * @param list<DayClosePaymentView> $payments in the organization's order of payment methods, then by id
     */
    public function __construct(
        public string $id,
        /** Y-m-d. */
        public string $date,
        public string $registerId,
        /** The register's current name; null when Income no longer offers it to this reader. */
        public ?string $registerName,
        public string $currency,
        /** Minor units; negative for a shortage. */
        public int $cashIncome,
        public bool $shortage,
        public array $storages,
        public array $payments,
        public string $closedById,
        public string $closedByFirstName,
        public string $closedByLastName,
        public \DateTimeImmutable $closedAt,
    ) {
    }
}
