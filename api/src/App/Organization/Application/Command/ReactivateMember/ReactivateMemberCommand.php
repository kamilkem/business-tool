<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\ReactivateMember;

use App\Shared\Application\Command\CommandInterface;

/** Gives a deactivated member their access back, on behalf of a member (Organization::reactivateMember()). */
final readonly class ReactivateMemberCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $userId,
    ) {
    }
}
