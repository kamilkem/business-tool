<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayClose;

final readonly class DayCloseStorageView
{
    /** @param list<DayCloseLineView> $lines by value, lowest first */
    public function __construct(
        public string $storageId,
        /** The storage's current name. */
        public string $name,
        /** The storage's mode at the close. */
        public string $mode,
        /** Minor units: what was counted in a counted storage, or put into a deposit one. */
        public int $total,
        /** Minor units: a counted storage's ledger balance just before the close; null for a deposit storage, and for a receipt. */
        public ?int $expectedOpening,
        /** Minor units: what the storage adds to the cash income (total − expected opening, or the deposit); null for a receipt. */
        public ?int $cashIncome,
        public array $lines,
    ) {
    }
}
