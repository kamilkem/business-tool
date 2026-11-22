<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListCashBalances;

use App\Shared\Application\Query\QueryInterface;

/**
 * Each cash storage's current balance in the current organization (TenantFilter), derived from the
 * ledger (ADR 0033 §4): every active storage, and a deactivated one while it still holds cash. For
 * owners and managers (§6; 403 for employees). Returns null when the actor is not an active member.
 */
final readonly class ListCashBalancesQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
