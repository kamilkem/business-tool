<?php

declare(strict_types=1);

namespace Ui\Http\Reporting;

use ApiPlatform\Metadata\ApiProperty;
use App\Reporting\Application\Query\GetBusinessSummary\BusinessCurrencySummaryView;
use App\Reporting\Application\Query\GetBusinessSummary\BusinessMonthSummaryView;

/** One calendar month of the business summary, in the organization's time zone. */
final readonly class BusinessSummaryMonth
{
    /** @param list<BusinessSummaryCurrency> $currencies */
    public function __construct(
        /** `YYYY-MM`. */
        public string $month,
        /** The month's first business date, `YYYY-MM-DD`. */
        public string $firstDate,
        /** The month's last business date, `YYYY-MM-DD`. */
        public string $lastDate,
        /** False for the current month, whose figures are a preview. */
        public bool $complete,
        /** The month's payroll run: none yet, a draft (its total is an estimate) or approved (its total is the cost). */
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['none', 'draft', 'approved']])]
        public string $payrollStatus,
        /** The month's payroll run, if any. */
        public ?string $payrollRunId,
        /** Figures per currency: the organization's current currency first, always present; others by code. */
        public array $currencies,
    ) {
    }

    public static function fromView(BusinessMonthSummaryView $view): self
    {
        return new self(
            month: $view->month,
            firstDate: $view->firstDate,
            lastDate: $view->lastDate,
            complete: $view->complete,
            payrollStatus: $view->payrollStatus->value,
            payrollRunId: $view->payrollRunId,
            currencies: array_map(
                static fn (BusinessCurrencySummaryView $currency): BusinessSummaryCurrency => BusinessSummaryCurrency::fromView($currency),
                $view->currencies,
            ),
        );
    }
}
