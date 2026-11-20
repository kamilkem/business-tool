<?php

declare(strict_types=1);

namespace App\Ksef\Application;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The member acting on the KSeF connection, with the organization's country and tax ID, asked
 * through the Organization module's queries. KsefAccess and the aggregate decide what the role may do.
 */
final readonly class KsefActor
{
    private function __construct(
        public Uuid $id,
        public MembershipRole $role,
        public string $countryCode,
        public ?string $taxId,
    ) {
    }

    /** @throws OrganizationNotFoundException when the actor is not an active member of the organization */
    public static function resolve(QueryBusInterface $queryBus, string $organizationId, string $actorId): self
    {
        return self::resolveOrNull($queryBus, $organizationId, $actorId) ?? throw OrganizationNotFoundException::forId($organizationId);
    }

    /** Null when the actor is not an active member of the organization. */
    public static function resolveOrNull(QueryBusInterface $queryBus, string $organizationId, string $actorId): ?self
    {
        /** @var MemberView|null $member */
        $member = $queryBus->ask(new GetMemberQuery($organizationId, $actorId, $actorId));
        /** @var OrganizationView|null $organization */
        $organization = null === $member ? null : $queryBus->ask(new GetOrganizationQuery($organizationId, $actorId));
        if (null === $member || null === $organization) {
            return null;
        }

        return new self(Uuid::fromString($member->userId), MembershipRole::from($member->role), $organization->countryCode, $organization->taxId);
    }
}
