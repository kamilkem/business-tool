<?php

declare(strict_types=1);

namespace App\Income\Application\Command\BookDayCloseRevenue;

use App\Shared\Application\Command\CommandInterface;

/**
 * Books a Cash flow end-of-day close into its register's revenue entry for the date (ADR 0033 §9):
 * an upsert keyed by the close's id. Sent only by Income's handlers of Cash flow's day close events,
 * never by a Ui processor; it acts as the member who closed, of any role, without IncomeAccess.
 * Amounts are integer minor units (ADR 0010). The organization must be the current tenant.
 */
final readonly class BookDayCloseRevenueCommand implements CommandInterface
{
    /** @param array<string, int> $nonCashAmounts minor units by payment method id */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $dayCloseId,
        /** The id of the entry when the close's register and date have none yet. */
        public string $newEntryId,
        public string $registerId,
        /** Y-m-d. */
        public string $businessDate,
        /** ISO 4217. */
        public string $currency,
        /** Negative for a shortage, which books 0 (revenue lines are non-negative, ADR 0022 §4). */
        public int $cashIncome,
        public array $nonCashAmounts,
    ) {
    }
}
