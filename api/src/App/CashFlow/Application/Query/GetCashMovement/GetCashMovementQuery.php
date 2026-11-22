<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetCashMovement;

use App\Shared\Application\Query\QueryInterface;

/**
 * One cash movement of the current organization (TenantFilter), for owners and managers (ADR 0033
 * §6; 403 for employees). Returns null when it does not exist there or the actor is not an active
 * member.
 */
final readonly class GetCashMovementQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $movementId,
    ) {
    }
}
