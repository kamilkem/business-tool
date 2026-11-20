<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

/** What each shift's length is rounded to on a payroll run (ADR 0027 §6). */
enum RoundingUnit: string
{
    case None = 'none';
    case Minutes15 = '15';
    case Minutes30 = '30';
    case Minutes60 = '60';

    /** The unit in seconds; null for exact lengths. */
    public function seconds(): ?int
    {
        return match ($this) {
            self::None => null,
            self::Minutes15 => 900,
            self::Minutes30 => 1800,
            self::Minutes60 => 3600,
        };
    }
}
