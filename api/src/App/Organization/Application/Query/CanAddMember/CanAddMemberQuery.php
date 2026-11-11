<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\CanAddMember;

use App\Shared\Application\Query\QueryInterface;

/**
 * Checks, without changing anything, that the actor may add a member with this role
 * (Organization::assertCanAddMember()). Asked before a user is created for an unknown e-mail, so a
 * refused request leaves no user behind. Returns null when the organization does not exist or the
 * actor is not a member; throws when the actor may not add the member.
 */
final readonly class CanAddMemberQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $role,
    ) {
    }
}
