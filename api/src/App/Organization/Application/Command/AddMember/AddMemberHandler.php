<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\AddMember;

use App\Organization\Domain\Event\MemberAdded;
use App\Organization\Domain\Exception\AlreadyAMemberException;
use App\Organization\Domain\Exception\MemberDeactivatedException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Adds the membership, then publishes MemberAdded (the invitation or notification e-mail, #9). */
final readonly class AddMemberHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private UserRepositoryInterface $users,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the organization does not exist or the actor is not a member
     * @throws OrganizationManagementForbiddenException when the actor is an employee
     * @throws RoleAssignmentForbiddenException         when the actor may not give this role
     * @throws UserNotFoundException
     * @throws AlreadyAMemberException
     * @throws MemberDeactivatedException               when the user is a deactivated member
     */
    public function __invoke(AddMemberCommand $command): void
    {
        $actorId = Uuid::fromString($command->actorId);
        $organization = $this->organizations->findById(Uuid::fromString($command->organizationId));
        if (null === $organization || !$organization->isActiveMember($actorId)) {
            throw OrganizationNotFoundException::forId($command->organizationId);
        }

        $user = $this->users->findById(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forId($command->userId);

        $organization->addMember($actorId, $user, MembershipRole::from($command->role), $this->clock->now());
        $this->organizations->save($organization);

        $this->events->publish(new MemberAdded(
            organizationId: $command->organizationId,
            userId: $command->userId,
            role: $command->role,
            addedById: $command->actorId,
        ));
    }
}
