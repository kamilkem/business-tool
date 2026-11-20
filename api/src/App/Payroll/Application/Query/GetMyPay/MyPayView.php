<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetMyPay;

use App\Payroll\Application\Query\GetPayrollRun\PaycheckAdjustmentView;
use App\Payroll\Domain\Model\Paycheck;
use App\Payroll\Domain\Model\PayEstimate;
use App\Payroll\Domain\Model\PayrollRun;
use App\Payroll\Domain\Model\ShiftLine;
use App\Shared\Domain\Time\BusinessPeriod;

final readonly class MyPayView
{
    public const string ESTIMATE = 'estimate';
    public const string APPROVED = 'approved';

    /** @param list<PaycheckAdjustmentView> $adjustments */
    public function __construct(
        public string $month,
        public string $firstDate,
        public string $lastDate,
        /** self::ESTIMATE or self::APPROVED. */
        public string $status,
        public string $currency,
        public int $shifts,
        public int $seconds,
        public int $paidSeconds,
        public int $shiftsWithoutRate,
        public int $openShifts,
        public int $payForHours,
        public array $adjustments,
        public int $adjustmentsTotal,
        public int $total,
        public ?\DateTimeImmutable $approvedAt,
    ) {
    }

    public static function estimate(BusinessPeriod $month, PayEstimate $estimate, string $currency): self
    {
        return new self(
            substr($month->first->toString(), 0, 7),
            $month->first->toString(),
            $month->last->toString(),
            self::ESTIMATE,
            $currency,
            $estimate->shifts,
            $estimate->seconds,
            $estimate->seconds,
            $estimate->shiftsWithoutRate,
            $estimate->openShifts,
            $estimate->payForHours,
            [],
            0,
            $estimate->payForHours,
            null,
        );
    }

    /** @param ?Paycheck $paycheck the actor's paycheck on the run; null when they had nothing to be paid */
    public static function approved(PayrollRun $run, ?Paycheck $paycheck): self
    {
        $month = $run->month();
        $lines = $paycheck?->shiftLines() ?? [];

        return new self(
            substr($month->first->toString(), 0, 7),
            $month->first->toString(),
            $month->last->toString(),
            self::APPROVED,
            $run->getCurrency(),
            \count($lines),
            array_sum(array_map(static fn (ShiftLine $line): int => $line->seconds, $lines)),
            $paycheck?->paidSeconds() ?? 0,
            $paycheck?->linesWithoutRate() ?? 0,
            0,
            $paycheck?->payForHours() ?? 0,
            array_map(PaycheckAdjustmentView::of(...), $paycheck?->adjustments() ?? []),
            $paycheck?->adjustmentsTotal() ?? 0,
            $paycheck?->total() ?? 0,
            $run->getApprovedAt(),
        );
    }
}
