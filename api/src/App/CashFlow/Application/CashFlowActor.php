<?php

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Model\CashFlowAccess;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An active member acting on Cash flow, with the organization settings Cash flow depends on: the
 * currency its denominations are in, the country and the time zone (ADR 0033). Any active member may
 * read what the end-of-day close needs; owners and managers change it (CashFlowAccess). Asked through
 * the Organization module's queries.
 */
final readonly class CashFlowActor
{
    private function __construct(
        public Uuid $id,
        public MembershipRole $role,
        public OrganizationView $organization,
    ) {
    }

    /**
     * For commands of owners and managers.
     *
     * @throws OrganizationNotFoundException     when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public static function managing(QueryBusInterface $queryBus, string $organizationId, string $actorId): self
    {
        $actor = self::find($queryBus, $organizationId, $actorId) ?? throw OrganizationNotFoundException::forId($organizationId);
        CashFlowAccess::assertCanManage($actor->id, $actor->role);

        return $actor;
    }

    /** For reads open to every active member: null when the actor is not one. */
    public static function memberOrNull(QueryBusInterface $queryBus, string $organizationId, string $actorId): ?self
    {
        return self::find($queryBus, $organizationId, $actorId);
    }

    private static function find(QueryBusInterface $queryBus, string $organizationId, string $actorId): ?self
    {
        /** @var MemberView|null $member */
        $member = $queryBus->ask(new GetMemberQuery($organizationId, $actorId, $actorId));
        if (null === $member) {
            return null;
        }

        /** @var OrganizationView|null $organization */
        $organization = $queryBus->ask(new GetOrganizationQuery($organizationId, $actorId));
        if (null === $organization) {
            return null;
        }

        return new self(Uuid::fromString($member->userId), MembershipRole::from($member->role), $organization);
    }
}
