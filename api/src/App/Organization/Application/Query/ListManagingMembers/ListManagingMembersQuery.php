<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\ListManagingMembers;

use App\Shared\Application\Query\QueryInterface;

/**
 * The active owners and managers of an organization (Organization::activeManagingMembers()), for
 * telling them about something that needs their decision. It has no actor: callers are event
 * handlers that run after a write that already checked who may do it. Returns null when the
 * organization does not exist.
 */
final readonly class ListManagingMembersQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
    ) {
    }
}
