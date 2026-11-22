<?php

declare(strict_types=1);

namespace App\Organization\Application\Query\GetMemberName;

use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetMemberNameHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    public function __invoke(GetMemberNameQuery $query): ?MemberNameView
    {
        if (!Uuid::isValid($query->organizationId) || !Uuid::isValid($query->actorId) || !Uuid::isValid($query->userId)) {
            return null;
        }

        $organization = $this->organizations->findById(Uuid::fromString($query->organizationId));
        if (null === $organization || !$organization->isActiveMember(Uuid::fromString($query->actorId))) {
            return null;
        }

        $user = $organization->membershipOf(Uuid::fromString($query->userId))?->getUser();

        return null === $user ? null : new MemberNameView($user->getId()->toRfc4122(), $user->getFirstName(), $user->getLastName());
    }
}
