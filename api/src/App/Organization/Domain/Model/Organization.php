<?php

declare(strict_types=1);

namespace App\Organization\Domain\Model;

use App\Organization\Domain\Exception\AlreadyAMemberException;
use App\Organization\Domain\Exception\LastOwnerException;
use App\Organization\Domain\Exception\MemberDeactivatedException;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\User\Domain\Model\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A business using the tool (a bar, a shop, ...) and the aggregate root of its memberships.
 * Root of the tenant tree: every module record belongs to exactly one organization
 * (TenantAwareInterface).
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization')]
class Organization
{
    /** Tax identifier (NIP in Poland). Required for KSeF integration. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(length: 2)]
    private string $countryCode;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Membership> */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'organization', cascade: ['persist'], orphanRemoval: true)]
    private Collection $memberships;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private Uuid $id,
        #[ORM\Column(length: 255)]
        private string $name,
        User $owner,
        \DateTimeImmutable $createdAt,
        ?string $taxId = null,
        string $countryCode = 'PL',
        string $currency = 'PLN',
        string $timezone = 'Europe/Warsaw',
    ) {
        $this->createdAt = $createdAt;
        $this->memberships = new ArrayCollection();
        $this->apply($name, $taxId, $countryCode, $currency, $timezone);
        $this->grantMembership($owner, MembershipRole::Owner, $createdAt);
    }

    /**
     * Adds a user to the organization with a role, on behalf of a member.
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     * @throws RoleAssignmentForbiddenException         when the actor may not give this role
     * @throws AlreadyAMemberException                  when the user is already a member
     * @throws MemberDeactivatedException               when the user is a deactivated member (reactivate them instead)
     */
    public function addMember(Uuid $actorId, User $user, MembershipRole $role, \DateTimeImmutable $now): Membership
    {
        $this->assertCanAddMember($actorId, $role);

        return $this->grantMembership($user, $role, $now);
    }

    /**
     * The permission part of addMember(), for callers that must check it before preparing the user
     * (an invited user is created only when the actor may add them).
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     * @throws RoleAssignmentForbiddenException         when the actor may not give this role
     */
    public function assertCanAddMember(Uuid $actorId, MembershipRole $role): void
    {
        $this->assertCanManage($actorId);
        $this->assertCanGiveRole($actorId, $role);
    }

    /**
     * The active membership of a member the actor may act on, e.g. to resend their invitation or
     * change their role: owners act on anyone, managers on employees only (the roles they may give,
     * MembershipRole::canAssign()). A deactivated member must be reactivated first.
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when a manager acts on an owner or manager
     * @throws MemberDeactivatedException               when the member is deactivated
     */
    public function manageableMembership(Uuid $actorId, Uuid $userId): Membership
    {
        $membership = $this->managedMembership($actorId, $userId);
        if (!$membership->isActive()) {
            throw MemberDeactivatedException::of($userId->toRfc4122(), $this->id->toRfc4122());
        }

        return $membership;
    }

    /**
     * Removes a member's access on behalf of a member, keeping the membership for the history that
     * refers to it. Owners deactivate anyone, themselves included; managers deactivate employees only.
     * The organization always keeps at least one active owner. Deactivating a deactivated member
     * changes nothing.
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when a manager acts on an owner or manager
     * @throws LastOwnerException                       when the member is the last active owner
     */
    public function deactivateMember(Uuid $actorId, Uuid $userId, \DateTimeImmutable $now): void
    {
        $membership = $this->managedMembership($actorId, $userId);
        if (!$membership->isActive()) {
            return;
        }

        if (MembershipRole::Owner === $membership->getRole() && 1 === $this->activeOwnerCount()) {
            throw LastOwnerException::of($this->id->toRfc4122());
        }

        $membership->deactivate($now);
    }

    /**
     * Gives a deactivated member their access back, with the role they had, on behalf of a member:
     * owners reactivate anyone, managers employees only. Reactivating an active member changes nothing.
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when a manager acts on an owner or manager
     */
    public function reactivateMember(Uuid $actorId, Uuid $userId): void
    {
        $this->managedMembership($actorId, $userId)->reactivate();
    }

    /**
     * Changes a member's role on behalf of a member. Owners change anyone to any role, themselves
     * included; managers act on employees only and give no role but employee (MembershipRole::canAssign()),
     * so in practice only owners promote or demote. The organization always keeps at least one owner.
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when the actor may not act on this member or give this role
     * @throws MemberDeactivatedException               when the member is deactivated
     * @throws LastOwnerException                       when the change would leave the organization without an active owner
     */
    public function changeMemberRole(Uuid $actorId, Uuid $userId, MembershipRole $role): void
    {
        $membership = $this->manageableMembership($actorId, $userId);
        $this->assertCanGiveRole($actorId, $role);

        if (MembershipRole::Owner === $membership->getRole() && MembershipRole::Owner !== $role && 1 === $this->activeOwnerCount()) {
            throw LastOwnerException::of($this->id->toRfc4122());
        }

        $membership->changeRole($role);
    }

    /**
     * One member as seen by a member: everyone sees their own membership (e.g. an owner who has just
     * stepped down to employee); owners and managers see every member, deactivated ones included.
     *
     * @throws OrganizationManagementForbiddenException when someone other than an owner or manager asks about another member
     */
    public function memberVisibleTo(Uuid $actorId, Uuid $userId): ?Membership
    {
        if (!$actorId->equals($userId)) {
            $this->assertCanManage($actorId);
        }

        return $this->membershipOf($userId);
    }

    /** The user's membership, active or deactivated. */
    public function membershipOf(Uuid $userId): ?Membership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()->getId()->equals($userId)) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * Changes the organization's settings on behalf of a member.
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     */
    public function changeSettings(
        Uuid $actorId,
        string $name,
        ?string $taxId,
        string $countryCode,
        string $currency,
        string $timezone,
    ): void {
        $this->assertCanManage($actorId);

        $this->apply($name, $taxId, $countryCode, $currency, $timezone);
    }

    /** The user is a member whose access was not removed (deactivateMember()). */
    public function isActiveMember(Uuid $userId): bool
    {
        return null !== $this->activeMembershipOf($userId);
    }

    /**
     * The members of the organization, as seen by a member managing the team: the active ones, or
     * also the deactivated ones on request.
     *
     * @return list<Membership>
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     */
    public function membersVisibleTo(Uuid $actorId, bool $includeDeactivated = false): array
    {
        $this->assertCanManage($actorId);

        return array_values(array_filter(
            $this->memberships->toArray(),
            static fn (Membership $membership): bool => $includeDeactivated || $membership->isActive(),
        ));
    }

    private function grantMembership(User $user, MembershipRole $role, \DateTimeImmutable $now): Membership
    {
        $existing = $this->membershipOf($user->getId());
        if (null !== $existing) {
            throw $existing->isActive()
                ? AlreadyAMemberException::of($user->getId()->toRfc4122(), $this->id->toRfc4122())
                : MemberDeactivatedException::of($user->getId()->toRfc4122(), $this->id->toRfc4122());
        }

        $membership = new Membership($this, $user, $role, $now);
        $this->memberships->add($membership);

        return $membership;
    }

    /**
     * A member the actor may act on, active or deactivated (manageableMembership()).
     *
     * @throws OrganizationManagementForbiddenException when the actor is not an owner or manager
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when a manager acts on an owner or manager
     */
    private function managedMembership(Uuid $actorId, Uuid $userId): Membership
    {
        $this->assertCanManage($actorId);

        $membership = $this->membershipOf($userId)
            ?? throw MemberNotFoundException::of($userId->toRfc4122(), $this->id->toRfc4122());
        $this->assertCanGiveRole($actorId, $membership->getRole());

        return $membership;
    }

    private function activeMembershipOf(Uuid $userId): ?Membership
    {
        $membership = $this->membershipOf($userId);

        return true === $membership?->isActive() ? $membership : null;
    }

    /** Only active owners and managers act on the organization. */
    private function assertCanManage(Uuid $actorId): void
    {
        if (!$this->activeMembershipOf($actorId)?->getRole()->canManageOrganization()) {
            throw OrganizationManagementForbiddenException::forUser($actorId->toRfc4122());
        }
    }

    /** Owners give any role; managers add employees only (MembershipRole::canAssign()). */
    private function assertCanGiveRole(Uuid $actorId, MembershipRole $role): void
    {
        $actorRole = $this->activeMembershipOf($actorId)?->getRole();
        if (null === $actorRole || !$actorRole->canAssign($role)) {
            throw RoleAssignmentForbiddenException::forRole($actorId->toRfc4122(), $role->value);
        }
    }

    private function activeOwnerCount(): int
    {
        return $this->memberships->filter(
            static fn (Membership $membership): bool => MembershipRole::Owner === $membership->getRole() && $membership->isActive(),
        )->count();
    }

    private function apply(string $name, ?string $taxId, string $countryCode, string $currency, string $timezone): void
    {
        $this->name = $name;
        $this->taxId = null === $taxId || '' === trim($taxId) ? null : trim($taxId);
        $this->countryCode = strtoupper($countryCode);
        $this->currency = strtoupper($currency);
        $this->timezone = $timezone;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Membership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }
}
