<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\RejectShiftChangeRequest;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager rejects a pending shift change request, optionally saying why. The shifts stay
 * as they are. The organization must be the current tenant (TenantContextInterface).
 */
final readonly class RejectShiftChangeRequestCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $requestId,
        public ?string $reason,
    ) {
    }
}
