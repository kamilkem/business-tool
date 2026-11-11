<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ClockOut;

use App\Shared\Application\Command\CommandInterface;

/**
 * Ends the person's shift at the server's now (ADR 0016 §4). The caller names the open shift it
 * found (GetCurrentShiftQuery), so it can return that shift afterwards; the handler checks that it
 * is the person's own. The organization must be the current tenant (TenantContextInterface).
 */
final readonly class ClockOutCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $shiftId,
        public string $userId,
    ) {
    }
}
