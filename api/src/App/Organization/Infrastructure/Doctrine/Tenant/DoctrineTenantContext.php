<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine\Tenant;

use App\Organization\Domain\Exception\NoOrganizationSelectedException;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class DoctrineTenantContext implements TenantContextInterface, ResetInterface
{
    private ?Organization $organization = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function switchTo(Organization $organization): void
    {
        $this->organization = $organization;

        $this->entityManager->getFilters()
            ->getFilter(TenantFilter::NAME)
            ->setParameter(TenantFilter::PARAMETER, $organization->getId()->toRfc4122());
    }

    public function hasOrganization(): bool
    {
        return null !== $this->organization;
    }

    public function getOrganization(): Organization
    {
        return $this->organization ?? throw NoOrganizationSelectedException::create();
    }

    public function reset(): void
    {
        $this->organization = null;

        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled(TenantFilter::NAME)) {
            // Re-enabling recreates the filter without parameters.
            $filters->disable(TenantFilter::NAME);
            $filters->enable(TenantFilter::NAME);
        }
    }
}
