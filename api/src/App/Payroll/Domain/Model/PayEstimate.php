<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * One person's pay so far in a month that no approved run covers (ADR 0027 §10, #66): their ended
 * shifts of the month at the rate of each shift's date, computed as a payroll run computes a
 * paycheck's pay for hours (PayrollSources::lines(), ShiftLine::exactAmount(), one half-up rounding)
 * but with exact time (no rounding) and no adjustments, which only a run has.
 */
final readonly class PayEstimate
{
    private function __construct(
        /** The ended shifts, oldest first. */
        public int $shifts,
        /** Real seconds of the ended shifts. */
        public int $seconds,
        /** Ended shifts with no rate in the organization's currency for their date: not in the pay. */
        public int $shiftsWithoutRate,
        /** Shifts still open: counted when they end. */
        public int $openShifts,
        /** Gross minor units: Σ(seconds × rate) / 3600, rounded half up once. */
        public int $payForHours,
    ) {
    }

    /** @param PayrollSources $sources the month's sources, all of them or narrowed to $userId */
    public static function of(PayrollSources $sources, Uuid $userId): self
    {
        $lines = $sources->lines(Rounding::exact())[$userId->toRfc4122()] ?? [];
        $open = array_filter($sources->shifts, static fn (WorkedShift $shift): bool => $shift->isOpen() && $shift->userId->equals($userId));

        return new self(
            \count($lines),
            array_sum(array_map(static fn (ShiftLine $line): int => $line->seconds, $lines)),
            \count(array_filter($lines, static fn (ShiftLine $line): bool => null === $line->rate)),
            \count($open),
            ShiftLine::roundHalfUp(array_sum(array_map(static fn (ShiftLine $line): int => $line->exactAmount(), $lines))),
        );
    }
}
