<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDenomination;

use App\Shared\Application\Query\QueryInterface;

/**
 * One denomination of the current organization (TenantFilter), in any currency, active or
 * deactivated, for any active member (ADR 0033 §6). Returns null when it does not exist there or
 * the actor is not an active member.
 */
final readonly class GetDenominationQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $denominationId,
    ) {
    }
}
