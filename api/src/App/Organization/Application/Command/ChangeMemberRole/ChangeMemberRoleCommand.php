<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\ChangeMemberRole;

use App\Shared\Application\Command\CommandInterface;

/** Gives a member of an organization another role, on behalf of a member (Organization::changeMemberRole()). */
final readonly class ChangeMemberRoleCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $userId,
        public string $role,
    ) {
    }
}
