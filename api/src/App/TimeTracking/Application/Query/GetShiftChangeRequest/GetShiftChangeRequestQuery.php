<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetShiftChangeRequest;

use App\Shared\Application\Query\QueryInterface;

/**
 * One shift change request, for an owner or manager or for the member who asked. Returns null when
 * the actor is not an active member, or the request is not one they may see.
 */
final readonly class GetShiftChangeRequestQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $requestId,
    ) {
    }
}
