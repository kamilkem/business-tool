<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/** Whether a denomination is a coin or a note (ADR 0033 §2): the close lists them in two groups. */
enum DenominationKind: string
{
    case Coin = 'coin';
    case Note = 'note';
}
