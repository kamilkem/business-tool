<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/** The rate that pays a person's shifts of one business date: a day rate or the hourly rate in effect (ADR 0027 §3). */
final readonly class ShiftRate
{
    public function __construct(
        public Uuid $userId,
        public BusinessDate $date,
        public ShiftRateKind $kind,
        /** The id of the HourlyRate or DayRate. */
        public Uuid $rateId,
        /** Gross minor units per hour. */
        public int $amount,
        /** ISO 4217. */
        public string $currency,
    ) {
    }
}
