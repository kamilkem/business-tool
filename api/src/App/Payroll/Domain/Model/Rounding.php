<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Payroll\Domain\Exception\InvalidRoundingException;

/**
 * How a payroll run rounds each shift's length (ADR 0027 §6), chosen at generation and kept on the
 * run. With RoundingUnit::None the length is exact to the second and the direction does not matter.
 */
final readonly class Rounding
{
    public function __construct(
        public RoundingUnit $unit,
        public RoundingDirection $direction,
    ) {
    }

    /** @throws InvalidRoundingException */
    public static function fromStrings(string $unit, string $direction): self
    {
        $roundingUnit = RoundingUnit::tryFrom($unit);
        $roundingDirection = RoundingDirection::tryFrom($direction);
        if (null === $roundingUnit || null === $roundingDirection) {
            throw InvalidRoundingException::of($unit, $direction);
        }

        return new self($roundingUnit, $roundingDirection);
    }

    public static function exact(): self
    {
        return new self(RoundingUnit::None, RoundingDirection::Nearest);
    }

    /** The paid length of a shift that lasted $seconds (not negative). */
    public function apply(int $seconds): int
    {
        $unit = $this->unit->seconds();
        if (null === $unit) {
            return $seconds;
        }

        return match ($this->direction) {
            RoundingDirection::Nearest => intdiv($seconds + intdiv($unit, 2), $unit) * $unit,
            RoundingDirection::Up => intdiv($seconds + $unit - 1, $unit) * $unit,
            RoundingDirection::Down => intdiv($seconds, $unit) * $unit,
        };
    }

    public function equals(self $other): bool
    {
        return $this->unit === $other->unit && $this->direction === $other->direction;
    }
}
