<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

/** How a paycheck was paid (ADR 0027 §9). */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
}
