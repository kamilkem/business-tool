<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use App\Payroll\Application\Query\GetPayrollRun\PaycheckAdjustmentView;
use App\Payroll\Application\Query\GetPayrollRun\PaycheckLineView;
use App\Payroll\Application\Query\GetPayrollRun\PaycheckView;
use App\Payroll\Domain\Model\ShiftRateKind;

/** One member's pay in a payroll run. */
final readonly class PayrollRunPaycheck
{
    /**
     * @param list<PayrollRunPaycheckLine>       $lines
     * @param list<PayrollRunAdjustment> $adjustments
     */
    public function __construct(
        public string $id,
        /** The member, active or deactivated. */
        public string $userId,
        /** The sum of the lines' paid seconds. */
        public int $paidSeconds,
        /** Σ(paid seconds × rate) / 3600, rounded half up once, in minor units; lines without a rate count 0. */
        public int $payForHours,
        /** The sum of the adjustments, in minor units. */
        public int $adjustmentsTotal,
        /** payForHours + adjustmentsTotal, in minor units. */
        public int $total,
        /** Lines whose member has no rate for the shift's date: the run cannot be approved until the rate is set and the run regenerated. */
        public int $linesWithoutRate,
        /** One line per ended shift of the month, by date. */
        public array $lines,
        /** Oldest first. */
        public array $adjustments,
    ) {
    }

    public static function fromView(PaycheckView $view): self
    {
        return new self(
            $view->id,
            $view->userId,
            $view->paidSeconds,
            $view->payForHours,
            $view->adjustmentsTotal,
            $view->total,
            $view->linesWithoutRate,
            array_map(static fn (PaycheckLineView $line): PayrollRunPaycheckLine => new PayrollRunPaycheckLine(
                $line->shiftId,
                $line->date,
                $line->seconds,
                $line->paidSeconds,
                null === $line->rateKind ? null : ShiftRateKind::from($line->rateKind),
                $line->rateId,
                $line->rate,
                $line->amount,
            ), $view->lines),
            array_map(static fn (PaycheckAdjustmentView $adjustment): PayrollRunAdjustment => new PayrollRunAdjustment(
                $adjustment->id,
                $adjustment->label,
                $adjustment->amount,
                $adjustment->createdAt,
                $adjustment->updatedAt,
            ), $view->adjustments),
        );
    }
}
