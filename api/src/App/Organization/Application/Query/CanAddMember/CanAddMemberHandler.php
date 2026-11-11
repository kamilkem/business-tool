<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\CanAddMember;

use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CanAddMemberHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /**
     * @return true|null true when allowed; null when the organization does not exist or the actor is not a member
     *
     * @throws OrganizationManagementForbiddenException when the actor is an employee
     * @throws RoleAssignmentForbiddenException         when the actor may not give this role
     */
    public function __invoke(CanAddMemberQuery $query): ?true
    {
        if (!Uuid::isValid($query->organizationId) || !Uuid::isValid($query->actorId)) {
            return null;
        }

        $actorId = Uuid::fromString($query->actorId);
        $organization = $this->organizations->findById(Uuid::fromString($query->organizationId));
        if (null === $organization || !$organization->isActiveMember($actorId)) {
            return null;
        }

        $organization->assertCanAddMember($actorId, MembershipRole::from($query->role));

        return true;
    }
}
