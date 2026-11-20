<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayrollRun;

use App\Payroll\Domain\Model\Paycheck;
use App\Payroll\Domain\Model\PaycheckAdjustment;
use App\Payroll\Domain\Model\ShiftLine;

final readonly class PaycheckView
{
    /**
     * @param list<PaycheckLineView>       $lines
     * @param list<PaycheckAdjustmentView> $adjustments
     */
    public function __construct(
        public string $id,
        public string $userId,
        public int $paidSeconds,
        public int $payForHours,
        public int $adjustmentsTotal,
        public int $total,
        public int $linesWithoutRate,
        public array $lines,
        public array $adjustments,
        /** YYYY-MM-DD; null while not marked paid. */
        public ?string $paidOn,
        /** 'cash' or 'transfer'; null while not marked paid. */
        public ?string $paymentMethod,
        public ?string $paidMarkedBy,
        public ?\DateTimeImmutable $paidMarkedAt,
    ) {
    }

    public static function of(Paycheck $paycheck): self
    {
        return new self(
            $paycheck->getId()->toRfc4122(),
            $paycheck->getUserId()->toRfc4122(),
            $paycheck->paidSeconds(),
            $paycheck->payForHours(),
            $paycheck->adjustmentsTotal(),
            $paycheck->total(),
            $paycheck->linesWithoutRate(),
            array_map(static fn (ShiftLine $line): PaycheckLineView => PaycheckLineView::of($line), $paycheck->shiftLines()),
            array_map(static fn (PaycheckAdjustment $adjustment): PaycheckAdjustmentView => PaycheckAdjustmentView::of($adjustment), $paycheck->adjustments()),
            $paycheck->getPaidOn()?->toString(),
            $paycheck->getPaymentMethod()?->value,
            $paycheck->getPaidMarkedBy()?->toRfc4122(),
            $paycheck->getPaidMarkedAt(),
        );
    }
}
