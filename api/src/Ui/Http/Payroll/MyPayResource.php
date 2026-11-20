<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Payroll\Application\Query\GetMyPay\MyPayView;
use App\Payroll\Application\Query\GetPayrollRun\PaycheckAdjustmentView;
use Ui\Http\Payroll\Provider\MyPayProvider;

/**
 * The signed-in member's own hours and gross pay in a month of the organization selected by the
 * X-Organization-Id header (ADR 0027 §10, #66), for the timeclock.
 */
#[ApiResource(
    shortName: 'MyPay',
    operations: [
        new Get(
            uriTemplate: '/my-pay',
            openapi: new Operation(
                summary: 'The signed-in member\'s own hours and gross pay in a month.',
                description: 'Always the signed-in member\'s own, whatever their role. When an approved payroll run covers the month, its paycheck (status `approved`: paid, possibly rounded, time, adjustments and total; all 0 without a paycheck). Otherwise an estimate (status `estimate`): the member\'s ended shifts of the month (a shift belongs to the business date it started on) at the rate of each shift\'s date in the organization\'s currency, exact time, no adjustments; open shifts are counted when they end, and shifts without a rate are counted in `shiftsWithoutRate` and not paid. A month after the current one, or one that is not YYYY-MM, is a 422.',
                responses: ['422' => new Response('The month is not YYYY-MM, or has not started yet.')],
            ),
            // MyPayInput validates the parameter; the schema below only documents it.
            queryParameterValidationEnabled: false,
            provider: MyPayProvider::class,
            parameters: [
                'month' => new QueryParameter(
                    schema: ['type' => 'string', 'pattern' => '^\d{4}-(0[1-9]|1[0-2])$'],
                    description: 'The calendar month (YYYY-MM); the current one in the organization\'s time zone by default.',
                ),
            ],
        ),
    ],
)]
final readonly class MyPayResource
{
    /** @param list<PayrollRunAdjustment> $adjustments */
    public function __construct(
        /** The calendar month (YYYY-MM). */
        public string $month,
        /** Its first business date, YYYY-MM-DD. */
        public string $firstDate,
        /** Its last business date, YYYY-MM-DD. */
        public string $lastDate,
        /** `approved`: the paycheck of an approved payroll run; `estimate`: computed from the shifts and rates so far. */
        #[ApiProperty(openapiContext: ['enum' => [MyPayView::ESTIMATE, MyPayView::APPROVED]])]
        public string $status,
        /** ISO 4217: the approved run's, or the organization's for an estimate. */
        public string $currency,
        /** Ended shifts counted. */
        public int $shifts,
        /** Their real length. */
        public int $seconds,
        /** The paid length: as rounded by the approved run; the real length for an estimate. */
        public int $paidSeconds,
        /** Ended shifts with no rate for their date: not in the pay until a rate is set. */
        public int $shiftsWithoutRate,
        /** Shifts still open, counted when they end (always 0 when approved). */
        public int $openShifts,
        /** Gross pay for the hours in minor units. */
        public int $payForHours,
        /** Bonuses and deductions of an approved paycheck; none in an estimate. */
        #[ApiProperty(readableLink: true)]
        public array $adjustments,
        public int $adjustmentsTotal,
        /** Gross total in minor units: pay for hours plus adjustments. */
        public int $total,
        /** When the run was approved; null for an estimate. */
        public ?\DateTimeImmutable $approvedAt,
    ) {
    }

    public static function fromView(MyPayView $view): self
    {
        return new self(
            $view->month,
            $view->firstDate,
            $view->lastDate,
            $view->status,
            $view->currency,
            $view->shifts,
            $view->seconds,
            $view->paidSeconds,
            $view->shiftsWithoutRate,
            $view->openShifts,
            $view->payForHours,
            array_map(static fn (PaycheckAdjustmentView $adjustment): PayrollRunAdjustment => new PayrollRunAdjustment(
                $adjustment->id,
                $adjustment->label,
                $adjustment->amount,
                $adjustment->createdAt,
                $adjustment->updatedAt,
            ), $view->adjustments),
            $view->adjustmentsTotal,
            $view->total,
            $view->approvedAt,
        );
    }
}
