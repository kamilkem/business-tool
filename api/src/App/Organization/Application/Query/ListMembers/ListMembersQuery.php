<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\ListMembers;

use App\Shared\Application\Query\QueryInterface;

/**
 * Members of an organization, for an owner or manager of it. Returns null when the organization
 * does not exist or the user is not a member; throws when the user is an employee.
 */
final readonly class ListMembersQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $userId,
    ) {
    }
}
