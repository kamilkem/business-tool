<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * An end-of-day close was recorded, and that is saved (ADR 0033 §9). Income books it into the
 * register's revenue entry for the date (#186): the method marked as cash gets max(0, cashIncome),
 * each non-cash method its amount, recorded as the member who closed.
 */
final readonly class DayCloseRecorded implements EventInterface
{
    /** @param array<string, int> $nonCashAmounts minor units by payment method id */
    public function __construct(
        public string $organizationId,
        public string $dayCloseId,
        public string $registerId,
        /** Y-m-d: the business date closed. */
        public string $date,
        /** ISO 4217. */
        public string $currency,
        /** Minor units; negative for a shortage. */
        public int $cashIncome,
        public array $nonCashAmounts,
        /** The member who closed, of any role. */
        public string $actorId,
    ) {
    }
}
