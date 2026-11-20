<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Model\PayrollAccess;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

/** The active member acting on pay rates and payroll runs, asked through the Organization module's queries (ADR 0027 §10). */
final readonly class PayrollActor
{
    private function __construct(
        public Uuid $id,
        public MembershipRole $role,
        private string $organizationId,
    ) {
    }

    /**
     * For commands: an owner or manager.
     *
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     */
    public static function manager(QueryBusInterface $queryBus, string $organizationId, string $actorId): self
    {
        $actor = self::find($queryBus, $organizationId, $actorId) ?? throw OrganizationNotFoundException::forId($organizationId);
        PayrollAccess::assertCanManage($actor->id, $actor->role);

        return $actor;
    }

    /**
     * For approving and reopening payroll runs: an owner (ADR 0027 §7, §9).
     *
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner
     */
    public static function owner(QueryBusInterface $queryBus, string $organizationId, string $actorId): self
    {
        $actor = self::find($queryBus, $organizationId, $actorId) ?? throw OrganizationNotFoundException::forId($organizationId);
        PayrollAccess::assertCanApprove($actor->id, $actor->role);

        return $actor;
    }

    /**
     * For marking paychecks paid and undoing the mark: an owner (ADR 0027 §9–10).
     *
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner
     */
    public static function payer(QueryBusInterface $queryBus, string $organizationId, string $actorId): self
    {
        $actor = self::find($queryBus, $organizationId, $actorId) ?? throw OrganizationNotFoundException::forId($organizationId);
        PayrollAccess::assertCanPay($actor->id, $actor->role);

        return $actor;
    }

    /** For queries: any active member, or null when the actor is not one. */
    public static function member(QueryBusInterface $queryBus, string $organizationId, string $actorId): ?self
    {
        return self::find($queryBus, $organizationId, $actorId);
    }

    /**
     * The id of a member of the organization, active or deactivated (ADR 0014: their shifts still
     * count, so they keep and can get rates). Check what the actor may see first (PayrollAccess).
     *
     * @throws MemberNotFoundException when the user is not a member
     */
    public function memberId(QueryBusInterface $queryBus, string $userId): Uuid
    {
        /** @var MemberView|null $member */
        $member = Uuid::isValid($userId) ? $queryBus->ask(new GetMemberQuery($this->organizationId, $this->id->toRfc4122(), $userId)) : null;

        return null === $member ? throw MemberNotFoundException::of($userId, $this->organizationId) : Uuid::fromString($member->userId);
    }

    /**
     * Whose rates a list shows: everyone's for an owner or manager unless narrowed to $userId; an
     * employee's own, also without $userId.
     *
     * @return ?Uuid the member to narrow to; null for everyone
     *
     * @throws PayrollAccessForbiddenException when an employee asks for someone else's rates
     * @throws MemberNotFoundException         when $userId is not a member of the organization
     */
    public function visibleMember(QueryBusInterface $queryBus, ?string $userId): ?Uuid
    {
        if (null === $userId) {
            return $this->role->canManageOrganization() ? null : $this->id;
        }
        if (Uuid::isValid($userId)) {
            PayrollAccess::assertCanRead($this->id, $this->role, Uuid::fromString($userId));
        }

        return $this->memberId($queryBus, $userId);
    }

    /**
     * The organization's settings a rate depends on: the currency it copies (ADR 0027 §3).
     *
     * @throws OrganizationNotFoundException when the actor cannot see the organization
     */
    public function organization(QueryBusInterface $queryBus): OrganizationView
    {
        /** @var OrganizationView|null $organization */
        $organization = $queryBus->ask(new GetOrganizationQuery($this->organizationId, $this->id->toRfc4122()));

        return $organization ?? throw OrganizationNotFoundException::forId($this->organizationId);
    }

    private static function find(QueryBusInterface $queryBus, string $organizationId, string $actorId): ?self
    {
        if (!Uuid::isValid($actorId)) {
            return null;
        }

        /** @var MemberView|null $member */
        $member = $queryBus->ask(new GetMemberQuery($organizationId, $actorId, $actorId));

        return null === $member ? null : new self(Uuid::fromString($member->userId), MembershipRole::from($member->role), $organizationId);
    }
}
