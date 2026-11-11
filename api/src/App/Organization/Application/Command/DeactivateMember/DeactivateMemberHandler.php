<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\DeactivateMember;

use App\Organization\Domain\Event\MemberDeactivated;
use App\Organization\Domain\Exception\LastOwnerException;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Deactivates the membership, then publishes MemberDeactivated (a pending invitation stops working). */
final readonly class DeactivateMemberHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the organization does not exist or the actor is not an active member
     * @throws OrganizationManagementForbiddenException when the actor is an employee
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when a manager acts on an owner or manager
     * @throws LastOwnerException                       when the member is the last active owner
     */
    public function __invoke(DeactivateMemberCommand $command): void
    {
        $actorId = Uuid::fromString($command->actorId);
        $organization = $this->organizations->findById(Uuid::fromString($command->organizationId));
        if (null === $organization || !$organization->isActiveMember($actorId)) {
            throw OrganizationNotFoundException::forId($command->organizationId);
        }

        if (!Uuid::isValid($command->userId)) {
            throw MemberNotFoundException::of($command->userId, $command->organizationId);
        }

        $userId = Uuid::fromString($command->userId);
        $organization->deactivateMember($actorId, $userId, $this->clock->now());
        $this->organizations->save($organization);

        $this->events->publish(new MemberDeactivated(
            organizationId: $command->organizationId,
            userId: $userId->toRfc4122(),
            deactivatedById: $command->actorId,
        ));
    }
}
