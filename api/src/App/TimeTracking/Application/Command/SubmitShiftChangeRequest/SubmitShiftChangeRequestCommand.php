<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\SubmitShiftChangeRequest;

use App\Shared\Application\Command\CommandInterface;

/**
 * A member asks an owner or manager to change their shifts (ADR 0016 §5): to enter a missing shift
 * (no shiftId; an end is required) or to give one of their own shifts new times (shiftId; a null end
 * keeps an open shift open). Times are ISO 8601 date-times with a time zone offset. The organization
 * must be the current tenant (TenantContextInterface): the request is stored in it.
 */
final readonly class SubmitShiftChangeRequestCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $requestId,
        public ?string $shiftId,
        public string $startedAt,
        public ?string $endedAt,
        public string $note,
    ) {
    }
}
