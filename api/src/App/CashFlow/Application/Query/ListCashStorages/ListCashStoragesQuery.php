<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\ListCashStorages;

use App\Shared\Application\Query\QueryInterface;

/**
 * The cash storages of the current organization (TenantFilter), for any active member (ADR 0033 §3,
 * §6): the active ones, and the deactivated ones after them when asked. They carry no balance.
 * Returns null when the actor is not an active member.
 */
final readonly class ListCashStoragesQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public bool $includeDeactivated = false,
    ) {
    }
}
