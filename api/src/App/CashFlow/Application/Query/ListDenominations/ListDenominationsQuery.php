<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListDenominations;

use App\Shared\Application\Query\QueryInterface;

/**
 * The denominations of the current organization (TenantFilter) in its current currency, for any
 * active member (ADR 0033 §2, §6): the active ones, and the deactivated ones after them when asked.
 * Returns null when the actor is not an active member.
 */
final readonly class ListDenominationsQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public bool $includeDeactivated = false,
    ) {
    }
}
