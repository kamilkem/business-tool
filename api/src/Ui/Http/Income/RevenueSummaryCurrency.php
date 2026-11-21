<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Application\Query\GetRevenueEntry\RevenueVatLineView;
use App\Income\Application\Query\GetRevenueSummary\RevenueCurrencySummaryView;
use App\Income\Application\Query\GetRevenueSummary\RevenueRegisterTotalView;

/** The totals of a period's entries in one currency, in its minor units. */
final readonly class RevenueSummaryCurrency
{
    /**
     * @param list<RevenueEntryPayment>    $payments
     * @param list<RevenueEntryVatLine>    $vatLines
     * @param list<RevenueSummaryRegister> $registers
     */
    public function __construct(
        /** ISO 4217 code of the amounts. */
        public string $currency,
        /** The sum of all payment lines. */
        public int $total,
        /** The number of entries in this currency. */
        public int $entryCount,
        /** The sum per payment method used, in the organization's order, deactivated methods last. */
        public array $payments,
        /** The sum per VAT rate used, highest rate first, exempt last; empty when no entry has a breakdown. */
        public array $vatLines,
        /** The sum of the VAT lines' gross amounts. */
        public int $vatGrossTotal,
        /** The sum of the VAT lines' VAT. */
        public int $vatTotal,
        /** The total per register with entries, by name; deactivated registers included. */
        public array $registers,
    ) {
    }

    public static function fromView(RevenueCurrencySummaryView $view): self
    {
        return new self(
            currency: $view->currency,
            total: $view->total,
            entryCount: $view->entryCount,
            payments: array_map(
                RevenueEntryPayment::fromView(...),
                $view->payments,
            ),
            vatLines: array_map(
                static fn (RevenueVatLineView $line): RevenueEntryVatLine => new RevenueEntryVatLine($line->rate, $line->gross, $line->vat, $line->net),
                $view->vatLines,
            ),
            vatGrossTotal: $view->vatGrossTotal,
            vatTotal: $view->vatTotal,
            registers: array_map(
                static fn (RevenueRegisterTotalView $register): RevenueSummaryRegister => new RevenueSummaryRegister($register->registerId, $register->registerName, $register->total, $register->entryCount),
                $view->registers,
            ),
        );
    }
}
