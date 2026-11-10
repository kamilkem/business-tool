<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\ListUserOrganizations;

use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListUserOrganizationsHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /** @return list<OrganizationView> */
    public function __invoke(ListUserOrganizationsQuery $query): array
    {
        return array_map(
            OrganizationView::fromOrganization(...),
            $this->organizations->findByMember(Uuid::fromString($query->userId)),
        );
    }
}
