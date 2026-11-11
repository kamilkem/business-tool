<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetShift;

use App\Shared\Application\Query\QueryInterface;

/**
 * One of the person's own shifts in the current organization (TenantFilter). Returns null when the
 * shift does not exist there or belongs to someone else.
 */
final readonly class GetShiftQuery implements QueryInterface
{
    public function __construct(
        public string $shiftId,
        public string $userId,
    ) {
    }
}
