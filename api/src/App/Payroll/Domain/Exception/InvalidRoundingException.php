<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/** Rounding is a unit (none, 15, 30, 60) and a direction (nearest, up, down) (ADR 0027 §6). */
final class InvalidRoundingException extends PayrollException
{
    public static function of(string $unit, string $direction): self
    {
        return new self(\sprintf('Rounding "%s" "%s" is not a unit (none, 15, 30, 60) and a direction (nearest, up, down).', $unit, $direction), ['unit' => $unit, 'direction' => $direction]);
    }
}
