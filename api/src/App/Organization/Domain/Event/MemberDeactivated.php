<?php

declare(strict_types=1);

namespace App\Organization\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * A member's access to an organization was removed (Organization::deactivateMember()) and saved.
 * The User module invalidates a pending invitation from here.
 */
final readonly class MemberDeactivated implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $userId,
        /** The owner or manager who deactivated the member. */
        public string $deactivatedById,
    ) {
    }
}
