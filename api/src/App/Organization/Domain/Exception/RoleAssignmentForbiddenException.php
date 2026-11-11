<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

/** A member who manages the organization tried to give a role above their own reach (MembershipRole::canAssign()). */
final class RoleAssignmentForbiddenException extends OrganizationException
{
    public static function forRole(string $actorId, string $role): self
    {
        return new self(\sprintf('User "%s" may not give the role "%s". Managers add employees only.', $actorId, $role));
    }
}
