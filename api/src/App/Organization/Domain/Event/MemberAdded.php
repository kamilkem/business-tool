<?php

declare(strict_types=1);

namespace App\Organization\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * A user was added to an organization (Organization::addMember()) and both the user and the
 * membership are saved. The invitation or notification e-mail is sent from here (#9, ADR 0013).
 */
final readonly class MemberAdded implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $userId,
        public string $role,
        /** The owner or manager who added the member. */
        public string $addedById,
    ) {
    }
}
