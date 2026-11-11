<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetMember;

use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetMemberHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /** @throws OrganizationManagementForbiddenException when an employee asks about another member */
    public function __invoke(GetMemberQuery $query): ?MemberView
    {
        if (!Uuid::isValid($query->organizationId) || !Uuid::isValid($query->actorId) || !Uuid::isValid($query->userId)) {
            return null;
        }

        $actorId = Uuid::fromString($query->actorId);
        $organization = $this->organizations->findById(Uuid::fromString($query->organizationId));
        if (null === $organization || null === $organization->membershipOf($actorId)) {
            return null;
        }

        $membership = $organization->memberVisibleTo($actorId, Uuid::fromString($query->userId));

        return null === $membership ? null : MemberView::fromMembership($membership);
    }
}
