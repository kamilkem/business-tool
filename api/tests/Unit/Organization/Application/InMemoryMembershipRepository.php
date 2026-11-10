<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Domain\Model\Membership;
use App\Organization\Domain\Repository\MembershipRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port, reading the memberships of an organization repository. */
final readonly class InMemoryMembershipRepository implements MembershipRepositoryInterface
{
    public function __construct(private InMemoryOrganizationRepository $organizations)
    {
    }

    public function findOne(Uuid $userId, Uuid $organizationId): ?Membership
    {
        return $this->organizations->findById($organizationId)?->membershipOf($userId);
    }

    public function findByUser(Uuid $userId): array
    {
        $memberships = [];
        foreach ($this->organizations->findByMember($userId) as $organization) {
            $membership = $organization->membershipOf($userId);
            if (null !== $membership) {
                $memberships[] = $membership;
            }
        }

        return $memberships;
    }
}
