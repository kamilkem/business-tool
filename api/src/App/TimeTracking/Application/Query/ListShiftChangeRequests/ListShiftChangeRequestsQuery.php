<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\ListShiftChangeRequests;

use App\Shared\Application\Query\QueryInterface;

/**
 * Shift change requests, newest first: everyone's for owners and managers, their own for employees;
 * all or in one ShiftChangeRequestStatus. Returns null when the actor is not an active member.
 */
final readonly class ListShiftChangeRequestsQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        /** ShiftChangeRequestStatus value, or null for all. */
        public ?string $status = null,
    ) {
    }
}
