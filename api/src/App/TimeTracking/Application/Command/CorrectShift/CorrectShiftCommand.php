<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\CorrectShift;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager sets a shift's times and note (ADR 0016 §5): all three, as they should be
 * afterwards. Times are ISO 8601 date-times with a time zone offset; a null end keeps an open
 * shift open. The organization must be the current tenant (TenantContextInterface).
 */
final readonly class CorrectShiftCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $shiftId,
        public string $startedAt,
        public ?string $endedAt,
        public ?string $note,
    ) {
    }
}
