<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetMemberName;

use App\Shared\Application\Query\QueryInterface;

/**
 * The name of a member of an organization, active or deactivated (ADR 0014), for any active member
 * of it, employees included: for showing who did something the actor may see (who closed a day,
 * ADR 0033 §6). Nothing else of the member. Returns null when the organization does not exist, the
 * actor is not an active member or the user is not a member.
 */
final readonly class GetMemberNameQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $userId,
    ) {
    }
}
