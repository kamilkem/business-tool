<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Shared\Domain\Time\BusinessPeriod;

/**
 * What a payroll run of a month is computed from, as the other modules report it now (ADR 0027
 * §5–7): the month's shifts (ended and open, deactivated members' included), the rate of every
 * person and date in the month, the pending shift change requests about the month and the
 * organization's currency.
 */
final readonly class PayrollSources
{
    /** @var array<string, ShiftRate> by "<userId> <Y-m-d>" */
    private array $rates;

    /**
     * @param list<WorkedShift> $shifts the shifts that started in the month, oldest first
     * @param list<ShiftRate>   $rates  the rate of each person and date that has one, in any currency
     */
    public function __construct(
        public BusinessPeriod $month,
        public array $shifts,
        array $rates,
        public int $pendingChangeRequests,
        /** The organization's currency (ISO 4217). */
        public string $currency,
    ) {
        $byKey = [];
        foreach ($rates as $rate) {
            $byKey[$rate->userId->toRfc4122().' '.$rate->date->toString()] = $rate;
        }
        $this->rates = $byKey;
    }

    public function openShiftCount(): int
    {
        return \count(array_filter($this->shifts, static fn (WorkedShift $shift): bool => $shift->isOpen()));
    }

    /**
     * The month's ended shifts paid with $rounding, by person. A rate in another currency than the
     * organization's counts as no rate (ADR 0027 §3).
     *
     * @return array<string, list<ShiftLine>> by user id, each person's lines oldest first
     */
    public function lines(Rounding $rounding): array
    {
        $lines = [];
        foreach ($this->shifts as $shift) {
            if (null === $shift->seconds) {
                continue;
            }
            $rate = $this->rates[$shift->userId->toRfc4122().' '.$shift->date->toString()] ?? null;
            if (null !== $rate && $rate->currency !== $this->currency) {
                $rate = null;
            }
            $lines[$shift->userId->toRfc4122()][] = new ShiftLine(
                $shift->shiftId,
                $shift->date,
                $shift->seconds,
                $rounding->apply($shift->seconds),
                $rate?->kind,
                $rate?->rateId,
                $rate?->amount,
            );
        }

        return $lines;
    }
}
