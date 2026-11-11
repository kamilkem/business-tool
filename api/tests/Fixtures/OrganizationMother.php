<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Organization\Domain\Model\Membership;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\User\Domain\Model\User;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Builds organizations for unit tests (no container, no database); also used to arrange API tests.
 * Without an explicit time, things happen at the global clock's now, which
 * ClockSensitiveTrait::mockTime() moves.
 */
final class OrganizationMother
{
    public static function ownedBy(User $owner, string $name = 'Corner Pub', ?\DateTimeImmutable $createdAt = null): Organization
    {
        return new Organization(Uuid::v7(), $name, $owner, $createdAt ?? Clock::get()->now());
    }

    /** Adds a member on behalf of the organization's first owner, who may give any role. */
    public static function addMember(Organization $organization, User $user, MembershipRole $role, ?\DateTimeImmutable $now = null): Membership
    {
        return $organization->addMember(self::ownerOf($organization)->getId(), $user, $role, $now ?? Clock::get()->now());
    }

    /** Deactivates a member on behalf of the organization's first active owner. */
    public static function deactivateMember(Organization $organization, User $user, ?\DateTimeImmutable $now = null): void
    {
        $organization->deactivateMember(self::ownerOf($organization)->getId(), $user->getId(), $now ?? Clock::get()->now());
    }

    /** The organization's first active owner. */
    public static function ownerOf(Organization $organization): User
    {
        foreach ($organization->getMemberships() as $membership) {
            if (MembershipRole::Owner === $membership->getRole() && $membership->isActive()) {
                return $membership->getUser();
            }
        }

        throw new \LogicException('Every organization has an owner.');
    }
}
