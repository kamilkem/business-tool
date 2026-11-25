<?php

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\PurchasingAccess;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

/** The owner or manager acting on purchasing data, asked through the Organization module's queries (ADR 0021). */
final readonly class PurchasingActor
{
    private function __construct(
        public Uuid $id,
        public MembershipRole $role,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException          when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     */
    public static function resolve(QueryBusInterface $queryBus, string $organizationId, string $actorId): self
    {
        $actor = self::find($queryBus, $organizationId, $actorId) ?? throw OrganizationNotFoundException::forId($organizationId);
        PurchasingAccess::assertCanManage($actor->id, $actor->role);

        return $actor;
    }

    /**
     * For queries: null when the actor is not an active member of the organization.
     *
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     */
    public static function resolveOrNull(QueryBusInterface $queryBus, string $organizationId, string $actorId): ?self
    {
        $actor = self::find($queryBus, $organizationId, $actorId);
        if (null !== $actor) {
            PurchasingAccess::assertCanManage($actor->id, $actor->role);
        }

        return $actor;
    }

    private static function find(QueryBusInterface $queryBus, string $organizationId, string $actorId): ?self
    {
        /** @var MemberView|null $member */
        $member = $queryBus->ask(new GetMemberQuery($organizationId, $actorId, $actorId));

        return null === $member ? null : new self(Uuid::fromString($member->userId), MembershipRole::from($member->role));
    }
}
