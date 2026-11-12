<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Domain\Exception\NoOrganizationSelectedException;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;

/** Fake implementing the port: keeps the selection, and every organization switched to, for assertions. */
final class InMemoryTenantContext implements TenantContextInterface
{
    private ?Organization $organization = null;

    /** @var list<string> ids of the organizations switched to, in order */
    public array $switches = [];

    public function switchTo(Organization $organization): void
    {
        $this->organization = $organization;
        $this->switches[] = $organization->getId()->toRfc4122();
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
    }
}
