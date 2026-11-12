<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRegister;

use App\Shared\Application\Query\QueryInterface;

/**
 * One register of the current organization (TenantFilter), active or deactivated, for an owner or
 * manager. Returns null when the register does not exist there or the actor is not an active member;
 * throws when the actor is an employee (ADR 0022 §7).
 */
final readonly class GetRegisterQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $registerId,
    ) {
    }
}
