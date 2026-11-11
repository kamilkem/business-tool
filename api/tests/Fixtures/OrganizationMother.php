<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Organization\Domain\Model\Membership;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\User\Domain\Model\User;
use Symfony\Component\Uid\Uuid;

/** Builds organizations for unit tests (no container, no database); also used to arrange API tests. */
final class OrganizationMother
{
    public static function ownedBy(User $owner, string $name = 'Corner Pub'): Organization
    {
        return new Organization(Uuid::v7(), $name, $owner);
    }

    /** Adds a member on behalf of the organization's first owner, who may give any role. */
    public static function addMember(Organization $organization, User $user, MembershipRole $role): Membership
    {
        return $organization->addMember(self::ownerOf($organization)->getId(), $user, $role);
    }

    public static function ownerOf(Organization $organization): User
    {
        foreach ($organization->getMemberships() as $membership) {
            if (MembershipRole::Owner === $membership->getRole()) {
                return $membership->getUser();
            }
        }

        throw new \LogicException('Every organization has an owner.');
    }
}
