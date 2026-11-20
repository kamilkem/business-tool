<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

/** Which way a shift's length is rounded to the run's unit (ADR 0027 §6); nearest rounds a half up. */
enum RoundingDirection: string
{
    case Nearest = 'nearest';
    case Up = 'up';
    case Down = 'down';
}
