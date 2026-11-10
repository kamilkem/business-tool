<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port: no container, no database. */
final class InMemoryOrganizationRepository implements OrganizationRepositoryInterface
{
    /** @var array<string, Organization> */
    private array $byId = [];

    public int $saveCallCount = 0;

    public function findById(Uuid $id): ?Organization
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByMember(Uuid $userId): array
    {
        $organizations = array_values(array_filter(
            $this->byId,
            static fn (Organization $organization): bool => null !== $organization->membershipOf($userId),
        ));
        usort($organizations, static fn (Organization $a, Organization $b): int => $a->getName() <=> $b->getName());

        return $organizations;
    }

    public function add(Organization $organization): void
    {
        $this->byId[$organization->getId()->toRfc4122()] = $organization;
    }

    public function save(Organization $organization): void
    {
        ++$this->saveCallCount;
        $this->byId[$organization->getId()->toRfc4122()] = $organization;
    }
}
