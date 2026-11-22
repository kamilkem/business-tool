<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/** The three kinds of CashMovement (ADR 0033 §4). */
enum CashMovementKind: string
{
    /** One storage, a signed number of pieces per denomination: change from the bank, a bank run, an opening float. */
    case Adjustment = 'adjustment';

    /** The same pieces out of one storage and into another. */
    case Transfer = 'transfer';

    /** An audit count of a deposit storage: absolute pieces, which replace its balance. */
    case Count = 'count';
}
