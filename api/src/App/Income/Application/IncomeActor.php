<?php

declare(strict_types=1);

namespace App\Income\Application;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Model\IncomeAccess;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The owner or manager acting on income, with the organization settings income depends on: the
 * currency entries copy, the country that gives the VAT rates and the time zone of "today"
 * (ADR 0022, ADR 0002, ADR 0017). Asked through the Organization module's queries.
 */
final readonly class IncomeActor
{
    private function __construct(
        public Uuid $id,
        public MembershipRole $role,
        public OrganizationView $organization,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     */
    public static function resolve(QueryBusInterface $queryBus, string $organizationId, string $actorId): self
    {
        $actor = self::find($queryBus, $organizationId, $actorId) ?? throw OrganizationNotFoundException::forId($organizationId);
        IncomeAccess::assertCanManage($actor->id, $actor->role);

        return $actor;
    }

    /**
     * For queries: null when the actor is not an active member of the organization.
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     */
    public static function resolveOrNull(QueryBusInterface $queryBus, string $organizationId, string $actorId): ?self
    {
        $actor = self::find($queryBus, $organizationId, $actorId);
        if (null !== $actor) {
            IncomeAccess::assertCanManage($actor->id, $actor->role);
        }

        return $actor;
    }

    /** Today in the organization's time zone. */
    public function today(ClockInterface $clock): BusinessDate
    {
        return BusinessDate::containing($clock->now(), new \DateTimeZone($this->organization->timezone));
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
