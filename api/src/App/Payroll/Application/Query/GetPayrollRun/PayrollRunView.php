<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayrollRun;

use App\Payroll\Application\Query\PayrollRunPayment;
use App\Payroll\Domain\Model\Paycheck;
use App\Payroll\Domain\Model\PayrollRun;

/** A payroll run with the paychecks the actor may see: everyone's for owners and managers, their own for an employee. */
final readonly class PayrollRunView
{
    /** @param list<PaycheckView> $paychecks by user id */
    public function __construct(
        public string $id,
        /** YYYY-MM */
        public string $month,
        public string $firstDate,
        public string $lastDate,
        /** 'draft' or 'approved'. */
        public string $status,
        public string $currency,
        public string $roundingUnit,
        public string $roundingDirection,
        public int $openShifts,
        public int $pendingChangeRequests,
        public int $linesWithoutRate,
        public int $total,
        /** Paychecks shown that are marked paid. */
        public int $paidPaychecks,
        /** Approved, and every paycheck shown is marked paid (derived, ADR 0027 §9). */
        public bool $paid,
        public array $paychecks,
        public string $createdBy,
        public \DateTimeImmutable $createdAt,
        public string $generatedBy,
        public \DateTimeImmutable $generatedAt,
        public ?string $approvedBy,
        public ?\DateTimeImmutable $approvedAt,
    ) {
    }

    /** @param list<Paycheck> $paychecks the ones to show */
    public static function of(PayrollRun $run, array $paychecks): self
    {
        $views = array_map(static fn (Paycheck $paycheck): PaycheckView => PaycheckView::of($paycheck), $paychecks);
        usort($views, static fn (PaycheckView $a, PaycheckView $b): int => $a->userId <=> $b->userId);
        $month = $run->month();

        return new self(
            $run->getId()->toRfc4122(),
            substr($month->first->toString(), 0, 7),
            $month->first->toString(),
            $month->last->toString(),
            $run->getStatus()->value,
            $run->getCurrency(),
            $run->rounding()->unit->value,
            $run->rounding()->direction->value,
            $run->getOpenShifts(),
            $run->getPendingChangeRequests(),
            array_sum(array_map(static fn (PaycheckView $paycheck): int => $paycheck->linesWithoutRate, $views)),
            array_sum(array_map(static fn (PaycheckView $paycheck): int => $paycheck->total, $views)),
            PayrollRunPayment::paidPaychecks($paychecks),
            PayrollRunPayment::isPaid($run, $paychecks),
            $views,
            $run->getCreatedBy()->toRfc4122(),
            $run->getCreatedAt(),
            $run->getGeneratedBy()->toRfc4122(),
            $run->getGeneratedAt(),
            $run->getApprovedBy()?->toRfc4122(),
            $run->getApprovedAt(),
        );
    }
}
