<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\WithdrawShiftChangeRequest;

use App\Shared\Application\Command\CommandInterface;

/**
 * The member who asked takes back their pending shift change request. The shifts stay as they are.
 * The organization must be the current tenant (TenantContextInterface).
 */
final readonly class WithdrawShiftChangeRequestCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $requestId,
    ) {
    }
}
