<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayRate;

use App\Payroll\Domain\Model\DayRate;
use App\Payroll\Domain\Model\HourlyRate;
use App\Shared\Domain\Time\BusinessDate;

/** The rate that pays a member's shifts that started on a business date (ADR 0027 §3, §5). */
final readonly class PayRateView
{
    public const string HOURLY = 'hourly';
    public const string DAY = 'day';

    public function __construct(
        public string $userId,
        /** YYYY-MM-DD */
        public string $date,
        /** self::DAY when a day rate replaces the hourly rate on that date, else self::HOURLY. */
        public string $kind,
        /** The id of the HourlyRate or DayRate. */
        public string $rateId,
        /** Gross minor units per hour. */
        public int $amount,
        /** ISO 4217: a rate in another currency than the run's counts as no rate (#62). */
        public string $currency,
    ) {
    }

    public static function of(HourlyRate|DayRate $rate, BusinessDate $date): self
    {
        return new self(
            $rate->getUserId()->toRfc4122(),
            $date->toString(),
            $rate instanceof DayRate ? self::DAY : self::HOURLY,
            $rate->getId()->toRfc4122(),
            $rate->getAmount(),
            $rate->getCurrency(),
        );
    }
}
