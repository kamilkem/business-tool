<?php

declare(strict_types=1);

namespace Ui\Http\Reporting;

use App\Reporting\Application\Query\GetBusinessSummary\BusinessCurrencySummaryView;

/** A month's figures in one currency, in its minor units. */
final readonly class BusinessSummaryCurrency
{
    public function __construct(
        /** ISO 4217 code. */
        public string $currency,
        /** The revenue total: the sum of the entries' payment lines. */
        public int $incomeGross,
        /** The VAT of the revenue entries' VAT breakdown. */
        public int $incomeVat,
        /** incomeGross − incomeVat. */
        public int $incomeNet,
        /** The revenue entries counted. */
        public int $revenueEntryCount,
        /** The gross total of the purchase invoices issued in the month; corrections count with their own, possibly negative, amounts. */
        public int $expensesGross,
        public int $expensesVat,
        public int $expensesNet,
        /** The purchase invoices counted. */
        public int $invoiceCount,
        /** The total of the month's approved payroll run in this currency, else 0. */
        public int $payrollCost,
        /** The total of the month's draft payroll run in this currency: an estimate, not counted in the result. Null without a draft. */
        public ?int $payrollDraftTotal,
        /** incomeNet − expensesNet − payrollCost. */
        public int $result,
    ) {
    }

    public static function fromView(BusinessCurrencySummaryView $view): self
    {
        return new self(
            currency: $view->currency,
            incomeGross: $view->incomeGross,
            incomeVat: $view->incomeVat,
            incomeNet: $view->incomeNet,
            revenueEntryCount: $view->revenueEntryCount,
            expensesGross: $view->expensesGross,
            expensesVat: $view->expensesVat,
            expensesNet: $view->expensesNet,
            invoiceCount: $view->invoiceCount,
            payrollCost: $view->payrollCost,
            payrollDraftTotal: $view->payrollDraftTotal,
            result: $view->result,
        );
    }
}
