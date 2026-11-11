<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetMember;

use App\Shared\Application\Query\QueryInterface;

/**
 * One member of an organization, for an owner or manager of it. Returns null when the organization
 * does not exist, the actor is not a member or the user is not a member; throws when the actor is
 * an employee.
 */
final readonly class GetMemberQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $userId,
    ) {
    }
}
