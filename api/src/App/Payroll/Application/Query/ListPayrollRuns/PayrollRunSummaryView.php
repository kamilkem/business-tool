<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListPayrollRuns;

use App\Payroll\Application\Query\PayrollRunPayment;
use App\Payroll\Domain\Model\Paycheck;
use App\Payroll\Domain\Model\PayrollRun;

final readonly class PayrollRunSummaryView
{
    public function __construct(
        public string $id,
        /** YYYY-MM */
        public string $month,
        public string $status,
        public string $currency,
        public string $roundingUnit,
        public string $roundingDirection,
        public int $paychecks,
        public int $paidSeconds,
        public int $linesWithoutRate,
        public int $total,
        /** Paychecks counted that are marked paid. */
        public int $paidPaychecks,
        /** Approved, and every paycheck counted is marked paid (derived, ADR 0027 §9). */
        public bool $paid,
        public \DateTimeImmutable $generatedAt,
        public ?\DateTimeImmutable $approvedAt,
    ) {
    }

    /** @param list<Paycheck> $paychecks the ones the actor sees */
    public static function of(PayrollRun $run, array $paychecks): self
    {
        $sum = static fn (\Closure $of): int => array_sum(array_map($of, $paychecks));

        return new self(
            $run->getId()->toRfc4122(),
            substr($run->month()->first->toString(), 0, 7),
            $run->getStatus()->value,
            $run->getCurrency(),
            $run->rounding()->unit->value,
            $run->rounding()->direction->value,
            \count($paychecks),
            $sum(static fn (Paycheck $paycheck): int => $paycheck->paidSeconds()),
            $sum(static fn (Paycheck $paycheck): int => $paycheck->linesWithoutRate()),
            $sum(static fn (Paycheck $paycheck): int => $paycheck->total()),
            PayrollRunPayment::paidPaychecks($paychecks),
            PayrollRunPayment::isPaid($run, $paychecks),
            $run->getGeneratedAt(),
            $run->getApprovedAt(),
        );
    }
}
