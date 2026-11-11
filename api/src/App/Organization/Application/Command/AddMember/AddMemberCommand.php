<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\AddMember;

use App\Shared\Application\Command\CommandInterface;

/** Adds an existing user (possibly just invited) to an organization with a role, on behalf of a member. */
final readonly class AddMemberCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $userId,
        public string $role,
    ) {
    }
}
