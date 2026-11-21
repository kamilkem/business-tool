<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\DeleteShift;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager deletes a shift for good (#28). The organization must be the current tenant
 * (TenantContextInterface).
 */
final readonly class DeleteShiftCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $shiftId,
    ) {
    }
}
