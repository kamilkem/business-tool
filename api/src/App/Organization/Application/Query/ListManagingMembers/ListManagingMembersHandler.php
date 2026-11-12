<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\ListManagingMembers;

use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListManagingMembersHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /** @return list<MemberView>|null */
    public function __invoke(ListManagingMembersQuery $query): ?array
    {
        if (!Uuid::isValid($query->organizationId)) {
            return null;
        }

        $organization = $this->organizations->findById(Uuid::fromString($query->organizationId));

        return null === $organization
            ? null
            : array_map(MemberView::fromMembership(...), $organization->activeManagingMembers());
    }
}
