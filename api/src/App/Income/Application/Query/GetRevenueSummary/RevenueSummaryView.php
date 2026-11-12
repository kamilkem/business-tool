<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRevenueSummary;

use App\Income\Domain\Model\RevenueEntry;
use App\Shared\Domain\Time\BusinessPeriod;

/**
 * The revenue of a period (ADR 0022): totals per currency, because an entry keeps the currency it was
 * recorded in (ADR 0022 §5, ADR 0010), and how many of the period's days have at least one entry.
 */
final readonly class RevenueSummaryView
{
    /**
     * @param list<RevenueCurrencySummaryView> $currencies the organization's current currency first,
     *                                                     also without entries; others by code
     */
    public function __construct(
        public string $from,
        public string $to,
        /** The days in the period. */
        public int $dayCount,
        /** The days of the period with at least one entry, of any register. */
        public int $daysWithEntries,
        public int $entryCount,
        public array $currencies,
    ) {
    }

    /** @param list<RevenueEntry> $entries the entries of the period */
    public static function of(BusinessPeriod $period, string $organizationCurrency, array $entries): self
    {
        $byCurrency = [$organizationCurrency => []];
        $days = [];
        foreach ($entries as $entry) {
            $byCurrency[$entry->getCurrency()][] = $entry;
            $days[$entry->getBusinessDate()->toString()] = true;
        }

        $others = array_diff_key($byCurrency, [$organizationCurrency => true]);
        ksort($others, \SORT_STRING);
        $currencies = [];
        foreach ([$organizationCurrency => $byCurrency[$organizationCurrency]] + $others as $currency => $currencyEntries) {
            $currencies[] = RevenueCurrencySummaryView::of((string) $currency, $currencyEntries);
        }

        return new self(
            $period->first->toString(),
            $period->last->toString(),
            $period->dayCount(),
            \count($days),
            \count($entries),
            $currencies,
        );
    }
}
