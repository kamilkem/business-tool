<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\AddShift;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager enters a shift a member worked but did not clock (ADR 0016 §5). Times are
 * ISO 8601 date-times with a time zone offset. The organization must be the current tenant
 * (TenantContextInterface): the shift is stored in it.
 */
final readonly class AddShiftCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $shiftId,
        public string $userId,
        public string $startedAt,
        public string $endedAt,
        public ?string $note,
    ) {
    }
}
