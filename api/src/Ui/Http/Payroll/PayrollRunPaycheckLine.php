<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use App\Payroll\Domain\Model\ShiftRateKind;

/** One shift on a paycheck, as the run computed it (a snapshot). */
final readonly class PayrollRunPaycheckLine
{
    public function __construct(
        public string $shiftId,
        /** The business date the shift started on (YYYY-MM-DD); the whole shift counts for it. */
        public string $date,
        /** The real length in seconds. */
        public int $seconds,
        /** The length in seconds after the run's rounding. */
        public int $paidSeconds,
        /** Which rate paid the shift: the member's day rate for its date or their hourly rate; null when the member has no rate for that date in the run's currency. */
        public ?ShiftRateKind $rateKind,
        /** The HourlyRate or DayRate; null without a rate. */
        public ?string $rateId,
        /** Gross minor units per hour; null without a rate. */
        public ?int $rate,
        /** This shift's pay rounded half up, for display: the paycheck's payForHours is rounded once from the exact sum, so it may differ from the sum of these by a few minor units. Null without a rate. */
        public ?int $amount,
    ) {
    }
}
