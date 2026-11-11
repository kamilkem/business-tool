<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\DeactivateMember;

use App\Shared\Application\Command\CommandInterface;

/** Removes a member's access to an organization, on behalf of a member (Organization::deactivateMember()). */
final readonly class DeactivateMemberCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $userId,
    ) {
    }
}
