<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetManagedShift;

use App\Shared\Application\Query\QueryInterface;

/**
 * Any member's shift in the current organization (TenantFilter), for an owner or manager who may
 * change it. Returns null when the shift does not exist there or the actor is not an active member;
 * throws when the actor is an employee.
 */
final readonly class GetManagedShiftQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $shiftId,
    ) {
    }
}
