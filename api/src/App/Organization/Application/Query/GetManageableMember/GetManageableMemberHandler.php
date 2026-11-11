<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetManageableMember;

use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetManageableMemberHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /**
     * @throws OrganizationManagementForbiddenException when the actor is an employee
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when a manager acts on an owner or manager
     */
    public function __invoke(GetManageableMemberQuery $query): ?MemberView
    {
        if (!Uuid::isValid($query->organizationId) || !Uuid::isValid($query->actorId)) {
            return null;
        }

        $actorId = Uuid::fromString($query->actorId);
        $organization = $this->organizations->findById(Uuid::fromString($query->organizationId));
        if (null === $organization || null === $organization->membershipOf($actorId)) {
            return null;
        }

        if (!Uuid::isValid($query->userId)) {
            throw MemberNotFoundException::of($query->userId, $query->organizationId);
        }

        return MemberView::fromMembership($organization->manageableMembership($actorId, Uuid::fromString($query->userId)));
    }
}
