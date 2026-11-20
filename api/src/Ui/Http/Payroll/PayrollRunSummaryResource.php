<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use App\Payroll\Application\Query\ListPayrollRuns\PayrollRunSummaryView;
use App\Payroll\Domain\Model\PayrollRunStatus;
use App\Payroll\Domain\Model\RoundingDirection;
use App\Payroll\Domain\Model\RoundingUnit;
use Ui\Http\Payroll\Provider\PayrollRunSummaryCollectionProvider;

/** A payroll run without its lines, for the list of runs of the organization selected by the X-Organization-Id header. */
#[ApiResource(
    shortName: 'PayrollRunSummary',
    operations: [
        new GetCollection(
            uriTemplate: '/payroll-runs',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the payroll runs of the selected organization.',
                description: 'Newest month first. Owners and managers see every run with the totals of every paycheck; an employee sees the approved runs that have a paycheck of theirs, with that paycheck\'s totals.',
            ),
            provider: PayrollRunSummaryCollectionProvider::class,
        ),
    ],
)]
final readonly class PayrollRunSummaryResource
{
    public function __construct(
        public string $id,
        /** The calendar month (YYYY-MM). */
        public string $month,
        public PayrollRunStatus $status,
        public string $currency,
        public RoundingUnit $roundingUnit,
        public RoundingDirection $roundingDirection,
        /** How many paychecks are counted below. */
        public int $paychecks,
        public int $paidSeconds,
        public int $linesWithoutRate,
        /** In minor units. */
        public int $total,
        public \DateTimeImmutable $generatedAt,
        public ?\DateTimeImmutable $approvedAt,
    ) {
    }

    public static function fromView(PayrollRunSummaryView $view): self
    {
        return new self(
            $view->id,
            $view->month,
            PayrollRunStatus::from($view->status),
            $view->currency,
            RoundingUnit::from($view->roundingUnit),
            RoundingDirection::from($view->roundingDirection),
            $view->paychecks,
            $view->paidSeconds,
            $view->linesWithoutRate,
            $view->total,
            $view->generatedAt,
            $view->approvedAt,
        );
    }
}
