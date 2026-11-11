<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ClockIn;

use App\Shared\Application\Command\CommandInterface;

/**
 * Opens a shift for the person at the server's now (ADR 0016 §4: the request carries no time).
 * The person clocks in for themselves only (ADR 0016 §1). The organization must be the current
 * tenant (TenantContextInterface): the shift is stored in it.
 */
final readonly class ClockInCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $shiftId,
        public string $userId,
    ) {
    }
}
