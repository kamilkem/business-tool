<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetCurrentShift;

use App\Shared\Application\Query\QueryInterface;

/**
 * The person's open shift (clocked in, not yet out) in the current organization (TenantFilter).
 * Returns null when they are not clocked in there.
 */
final readonly class GetCurrentShiftQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
