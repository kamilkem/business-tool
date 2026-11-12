<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/**
 * How the takings were paid. A fixed set in v1 (ADR 0022 §4); entries store them as lines, so a
 * per-organization list can replace the enum later (#110).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Voucher = 'voucher';
}
