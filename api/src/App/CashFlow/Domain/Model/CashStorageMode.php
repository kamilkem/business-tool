<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/**
 * How the end-of-day close handles a cash storage (ADR 0033 §3, §6).
 *
 * - `counted`: the close enters a full count of what stays in it, which replaces its balance.
 * - `deposit`: the close enters only what was put in; only these storages take audit counts.
 */
enum CashStorageMode: string
{
    case Counted = 'counted';
    case Deposit = 'deposit';
}
