<?php

declare(strict_types=1);

namespace App\Reporting\Application\Query\GetBusinessSummary;

final readonly class BusinessSummaryView
{
    /** @param list<BusinessMonthSummaryView> $months oldest first, one per calendar month of the period */
    public function __construct(
        /** YYYY-MM */
        public string $from,
        /** YYYY-MM */
        public string $to,
        /** The organization's current currency (ISO 4217), first in every month's currencies. */
        public string $currency,
        public array $months,
    ) {
    }
}
