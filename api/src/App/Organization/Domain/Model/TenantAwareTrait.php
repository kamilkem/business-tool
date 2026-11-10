<?php

declare(strict_types=1);

namespace App\Organization\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

trait TenantAwareTrait
{
    /** Uninitialized until assigned explicitly or by TenantAssignListener on persist. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    public function getOrganization(): ?Organization
    {
        return $this->organization ?? null;
    }

    public function assignOrganization(Organization $organization): void
    {
        if (isset($this->organization) && !$this->organization->getId()->equals($organization->getId())) {
            // Only reachable through a bug: records never move between organizations.
            throw new \LogicException('An entity cannot be moved to another organization.');
        }

        $this->organization = $organization;
    }
}
