<?php

declare(strict_types=1);

namespace App\Secret\Application;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The active member setting or removing a secret, asked through the Organization module's query.
 * The aggregate decides whether the role may (ADR 0026 §5: owners only).
 */
final readonly class SecretActor
{
    private function __construct(
        public Uuid $id,
        public MembershipRole $role,
    ) {
    }

    /** @throws OrganizationNotFoundException when the actor is not an active member of the organization */
    public static function resolve(QueryBusInterface $queryBus, string $organizationId, string $actorId): self
    {
        /** @var MemberView|null $member */
        $member = $queryBus->ask(new GetMemberQuery($organizationId, $actorId, $actorId));
        if (null === $member) {
            throw OrganizationNotFoundException::forId($organizationId);
        }

        return new self(Uuid::fromString($member->userId), MembershipRole::from($member->role));
    }
}
