<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayrollRun;

use App\Payroll\Domain\Model\ShiftLine;

final readonly class PaycheckLineView
{
    public function __construct(
        public string $shiftId,
        /** The business date the shift started on (YYYY-MM-DD). */
        public string $date,
        /** The real length. */
        public int $seconds,
        /** The length after the run's rounding. */
        public int $paidSeconds,
        /** 'hourly' or 'day'; null when the shift has no rate. */
        public ?string $rateKind,
        public ?string $rateId,
        /** Gross minor units per hour; null when the shift has no rate. */
        public ?int $rate,
        /** This shift's pay rounded half up, for display; null without a rate. */
        public ?int $amount,
    ) {
    }

    public static function of(ShiftLine $line): self
    {
        return new self(
            $line->shiftId->toRfc4122(),
            $line->date->toString(),
            $line->seconds,
            $line->paidSeconds,
            $line->rateKind?->value,
            $line->rateId?->toRfc4122(),
            $line->rate,
            $line->amount(),
        );
    }
}
