<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\ReactivateMember;

use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ReactivateMemberHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the organization does not exist or the actor is not an active member
     * @throws OrganizationManagementForbiddenException when the actor is an employee
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when a manager acts on an owner or manager
     */
    public function __invoke(ReactivateMemberCommand $command): void
    {
        $actorId = Uuid::fromString($command->actorId);
        $organization = $this->organizations->findById(Uuid::fromString($command->organizationId));
        if (null === $organization || !$organization->isActiveMember($actorId)) {
            throw OrganizationNotFoundException::forId($command->organizationId);
        }

        if (!Uuid::isValid($command->userId)) {
            throw MemberNotFoundException::of($command->userId, $command->organizationId);
        }

        $organization->reactivateMember($actorId, Uuid::fromString($command->userId));
        $this->organizations->save($organization);
    }
}
