<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetOrganization;

use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetOrganizationHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    public function __invoke(GetOrganizationQuery $query): ?OrganizationView
    {
        if (!Uuid::isValid($query->organizationId)) {
            return null;
        }

        $organization = $this->organizations->findById(Uuid::fromString($query->organizationId));
        if (null === $organization || null === $organization->membershipOf(Uuid::fromString($query->userId))) {
            return null;
        }

        return OrganizationView::fromOrganization($organization);
    }
}
