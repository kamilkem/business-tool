<?php

declare(strict_types=1);

namespace App\Reporting\Application\Query\GetBusinessSummary;

use App\Payroll\Application\Query\ListPayrollRuns\PayrollRunSummaryView;
use App\Reporting\Domain\Model\MonthPayrollStatus;

final readonly class BusinessMonthSummaryView
{
    /** @param list<BusinessCurrencySummaryView> $currencies the organization's current currency first, always present; others by code */
    public function __construct(
        /** YYYY-MM */
        public string $month,
        /** The month's first business date, YYYY-MM-DD. */
        public string $firstDate,
        /** The month's last business date, YYYY-MM-DD. */
        public string $lastDate,
        /** False for the current month: its figures are a preview. */
        public bool $complete,
        public MonthPayrollStatus $payrollStatus,
        public ?string $payrollRunId,
        public array $currencies,
    ) {
    }

    /** @param list<BusinessCurrencySummaryView> $currencies */
    public static function of(string $month, string $firstDate, string $lastDate, bool $complete, ?PayrollRunSummaryView $run, array $currencies): self
    {
        return new self(
            $month,
            $firstDate,
            $lastDate,
            $complete,
            null === $run ? MonthPayrollStatus::None : MonthPayrollStatus::from($run->status),
            $run?->id,
            $currencies,
        );
    }
}
