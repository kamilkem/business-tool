<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetMe;

use App\Organization\Domain\Repository\MembershipRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetMeHandler implements QueryHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private MembershipRepositoryInterface $memberships,
    ) {
    }

    public function __invoke(GetMeQuery $query): MeView
    {
        $userId = Uuid::fromString($query->userId);
        $user = $this->users->findById($userId) ?? throw UserNotFoundException::forId($query->userId);

        $memberships = [];
        foreach ($this->memberships->findActiveByUser($userId) as $membership) {
            $memberships[] = new MeMembershipView(
                $membership->getId()->toRfc4122(),
                $membership->getRole()->value,
                $membership->getOrganization()->getId()->toRfc4122(),
                $membership->getOrganization()->getName(),
                $membership->getOrganization()->getTimezone(),
                $membership->getOrganization()->getCurrency(),
            );
        }

        return new MeView(
            $user->getId()->toRfc4122(),
            $user->getEmail(),
            $user->getFirstName(),
            $user->getLastName(),
            $memberships,
        );
    }
}
