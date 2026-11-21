<?php

declare(strict_types=1);

namespace Ui\Http\Reporting;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Reporting\Application\Query\GetBusinessSummary\BusinessMonthSummaryView;
use App\Reporting\Application\Query\GetBusinessSummary\BusinessSummaryView;
use App\Reporting\Application\Query\GetBusinessSummary\GetBusinessSummaryQuery;
use Ui\Http\Reporting\Provider\BusinessSummaryProvider;

/**
 * The business figures of the organization selected by the X-Organization-Id header for each month
 * of a period (ADR 0029): income, expenses, payroll and the result. Owners and managers only.
 */
#[ApiResource(
    shortName: 'BusinessSummary',
    operations: [
        new Get(
            uriTemplate: '/business-summary',
            openapi: new Operation(
                summary: 'Income, expenses, payroll and the result of the selected organization, per month.',
                description: 'Owners and managers only (403 otherwise). One entry per calendar month from `from` to `to` (in the organization\'s time zone), oldest first, at most '.GetBusinessSummaryQuery::MAX_MONTHS.' months. The current month is a preview (`complete: false`); a month that has not started is a 422. Amounts are integer minor units, per currency: `currencies[0]` is always the organization\'s current currency, also for an empty month (all 0), and other currencies follow by code. Income is the revenue entries (gross, VAT, net = gross − VAT); expenses are the purchase invoices issued in the month; payroll cost is the month\'s approved run only, while a draft run\'s total is `payrollDraftTotal`, an estimate. `result` = incomeNet − expensesNet − payrollCost.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('A month is missing or invalid, to is before from, the period is too long, or a month has not started.'),
                ],
            ),
            // BusinessSummaryInput validates the parameters, cross-field rules included; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: BusinessSummaryProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$', 'example' => '2026-01'],
                    description: 'First month, inclusive (YYYY-MM).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$', 'example' => '2026-11'],
                    description: 'Last month, inclusive (YYYY-MM); at most '.GetBusinessSummaryQuery::MAX_MONTHS.' months from the first, and not after the current month.',
                    required: true,
                ),
            ],
        ),
    ],
)]
final readonly class BusinessSummaryResource
{
    /** @param list<BusinessSummaryMonth> $months */
    public function __construct(
        /** First month of the period, `YYYY-MM`. */
        public string $from,
        /** Last month of the period, inclusive, `YYYY-MM`. */
        public string $to,
        /** The organization's current currency (ISO 4217), first in every month's currencies. */
        public string $currency,
        /** One entry per month, oldest first. */
        public array $months,
    ) {
    }

    public static function fromView(BusinessSummaryView $view): self
    {
        return new self(
            from: $view->from,
            to: $view->to,
            currency: $view->currency,
            months: array_map(
                static fn (BusinessMonthSummaryView $month): BusinessSummaryMonth => BusinessSummaryMonth::fromView($month),
                $view->months,
            ),
        );
    }
}
