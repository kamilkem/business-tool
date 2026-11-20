<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Shared\Domain\Time\BusinessDate;
use Symfony\Component\Uid\Uuid;

/**
 * One shift as a payroll run pays it (ADR 0027 §6): its length, its paid (rounded) length and its
 * rate, or none when the person has no rate in the run's currency for the shift's date.
 */
final readonly class ShiftLine
{
    public function __construct(
        public Uuid $shiftId,
        public BusinessDate $date,
        public int $seconds,
        public int $paidSeconds,
        public ?ShiftRateKind $rateKind,
        public ?Uuid $rateId,
        /** Gross minor units per hour; null without a rate. */
        public ?int $rate,
    ) {
    }

    /**
     * This shift's pay rounded half up to a minor unit, for display only: a paycheck's pay for hours
     * is rounded once from the exact sum of its lines (Paycheck::payForHours()), so it may differ
     * from the sum of these by a few minor units. Null without a rate.
     */
    public function amount(): ?int
    {
        return null === $this->rate ? null : self::roundHalfUp($this->exactAmount());
    }

    /** Paid seconds × rate: the pay in minor units × 3600, exact; 0 without a rate. */
    public function exactAmount(): int
    {
        return $this->paidSeconds * ($this->rate ?? 0);
    }

    /** Rounds minor units × 3600 (not negative) half up to minor units. */
    public static function roundHalfUp(int $exact): int
    {
        return intdiv($exact + 1800, 3600);
    }

    /** What makes two lines pay the same; a run is up to date when its lines all match a recomputation. */
    public function signature(): string
    {
        return implode('|', [
            $this->shiftId->toRfc4122(),
            $this->date->toString(),
            $this->seconds,
            $this->paidSeconds,
            $this->rateKind->value ?? '-',
            $this->rateId?->toRfc4122() ?? '-',
            $this->rate ?? '-',
        ]);
    }
}
