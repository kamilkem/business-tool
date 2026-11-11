<?php

declare(strict_types=1);

namespace App\Organization\Domain\Model;

use App\User\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Grants a user access to an organization with a role. Part of the Organization aggregate:
 * created only through Organization::addMember(), changed through Organization::changeMemberRole(),
 * deactivateMember() and reactivateMember().
 *
 * A deactivated membership is kept, not deleted, because the person's history (shifts, paychecks)
 * refers to it; it no longer gives access to the organization.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\UniqueConstraint(fields: ['user', 'organization'])]
class Membership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When the person's access was removed; null while the membership is active. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    /** @internal use Organization::addMember() */
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'memberships')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Organization $organization,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 20, enumType: MembershipRole::class)]
        private MembershipRole $role,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $createdAt;
    }

    /** @internal use Organization::changeMemberRole() */
    public function changeRole(MembershipRole $role): void
    {
        $this->role = $role;
    }

    /** @internal use Organization::deactivateMember() */
    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->deactivatedAt ??= $now;
    }

    /** @internal use Organization::reactivateMember() */
    public function reactivate(): void
    {
        $this->deactivatedAt = null;
    }

    public function isActive(): bool
    {
        return null === $this->deactivatedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }
}
