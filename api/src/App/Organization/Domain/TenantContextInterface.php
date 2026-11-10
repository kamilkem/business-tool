<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\Organization\Domain\Exception\NoOrganizationSelectedException;
use App\Organization\Domain\Model\Organization;

/**
 * The organization the current request or console command works on. Selecting one restricts
 * every tenant-aware query to it.
 */
interface TenantContextInterface
{
    public function switchTo(Organization $organization): void;

    public function hasOrganization(): bool;

    /** @throws NoOrganizationSelectedException */
    public function getOrganization(): Organization;

    public function reset(): void;
}
