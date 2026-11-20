<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

enum ShiftRateKind: string
{
    case Hourly = 'hourly';
    case Day = 'day';
}
