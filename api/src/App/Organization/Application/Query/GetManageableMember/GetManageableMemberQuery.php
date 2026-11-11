<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetManageableMember;

use App\Shared\Application\Query\QueryInterface;

/**
 * A member the actor may act on (Organization::manageableMembership()), e.g. before resending their
 * invitation. Returns null when the organization does not exist or the actor is not a member;
 * throws when the user is not a member or the actor may not act on them.
 */
final readonly class GetManageableMemberQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $userId,
    ) {
    }
}
