<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ExportPayrollRun;

final readonly class PaycheckLineExportView
{
    public function __construct(
        public string $shiftId,
        /** The business date the shift started on (YYYY-MM-DD). */
        public string $date,
        /** Null when Time tracking no longer has the shift (never expected: an approved month is locked). */
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt,
        public int $seconds,
        public int $paidSeconds,
        /** 'hourly' or 'day'; null without a rate (never in an approved run). */
        public ?string $rateKind,
        /** Gross minor units per hour. */
        public ?int $rate,
        /** This shift's pay rounded half up, for display: the paycheck's pay for hours is rounded once. */
        public ?int $amount,
    ) {
    }
}
