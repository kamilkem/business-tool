<?php

declare(strict_types=1);

namespace App\Reporting\Application\Query\GetBusinessSummary;

use App\Expenses\Application\Query\ListPurchaseInvoices\PurchaseInvoiceCurrencyTotalView;
use App\Income\Application\Query\GetRevenueSummary\RevenueCurrencySummaryView;
use App\Payroll\Application\Query\ListPayrollRuns\PayrollRunSummaryView;
use App\Payroll\Domain\Model\PayrollRunStatus;

/** A month's figures in one currency, in its minor units (ADR 0010, ADR 0029 §4). */
final readonly class BusinessCurrencySummaryView
{
    public function __construct(
        /** ISO 4217 code. */
        public string $currency,
        /** The revenue total: the sum of the entries' payment lines. */
        public int $incomeGross,
        /** The VAT of the entries' VAT breakdown. */
        public int $incomeVat,
        /** incomeGross − incomeVat. */
        public int $incomeNet,
        public int $revenueEntryCount,
        /** The purchase invoices issued in the month; corrections count with their own amounts. */
        public int $expensesGross,
        public int $expensesVat,
        public int $expensesNet,
        public int $invoiceCount,
        /** The total of the month's approved payroll run, else 0. */
        public int $payrollCost,
        /** The total of the month's draft payroll run (an estimate, not in the result), else null. */
        public ?int $payrollDraftTotal,
        /** incomeNet − expensesNet − payrollCost. */
        public int $result,
    ) {
    }

    /** @param PayrollRunSummaryView|null $run the month's run when it is in $currency */
    public static function of(string $currency, ?RevenueCurrencySummaryView $income, ?PurchaseInvoiceCurrencyTotalView $expenses, ?PayrollRunSummaryView $run): self
    {
        $incomeGross = $income->total ?? 0;
        $incomeVat = $income->vatTotal ?? 0;
        $expensesNet = $expenses->netTotal ?? 0;
        $approved = null !== $run && PayrollRunStatus::Approved->value === $run->status;
        $payrollCost = $approved ? $run->total : 0;

        return new self(
            $currency,
            $incomeGross,
            $incomeVat,
            $incomeGross - $incomeVat,
            $income->entryCount ?? 0,
            $expenses->grossTotal ?? 0,
            $expenses->vatTotal ?? 0,
            $expensesNet,
            $expenses->invoiceCount ?? 0,
            $payrollCost,
            null !== $run && !$approved ? $run->total : null,
            $incomeGross - $incomeVat - $expensesNet - $payrollCost,
        );
    }
}
