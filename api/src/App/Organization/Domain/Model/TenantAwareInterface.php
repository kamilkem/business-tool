<?php

declare(strict_types=1);

namespace App\Organization\Domain\Model;

/**
 * Marks an entity of any module as owned by one organization.
 *
 * Every query on such an entity is restricted to the current organization (TenantFilter), and
 * new instances get the current organization on persist (TenantAssignListener). Use
 * TenantAwareTrait for the mapping.
 */
interface TenantAwareInterface
{
    public function getOrganization(): ?Organization;

    public function assignOrganization(Organization $organization): void;
}
