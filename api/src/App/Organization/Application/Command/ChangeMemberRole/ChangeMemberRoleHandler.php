<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\ChangeMemberRole;

use App\Organization\Domain\Exception\LastOwnerException;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeMemberRoleHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the organization does not exist or the actor is not a member
     * @throws OrganizationManagementForbiddenException when the actor is an employee
     * @throws MemberNotFoundException                  when the user is not a member
     * @throws RoleAssignmentForbiddenException         when the actor may not act on this member or give this role
     * @throws LastOwnerException                       when the organization would be left without an owner
     */
    public function __invoke(ChangeMemberRoleCommand $command): void
    {
        $actorId = Uuid::fromString($command->actorId);
        $organization = $this->organizations->findById(Uuid::fromString($command->organizationId));
        if (null === $organization || null === $organization->membershipOf($actorId)) {
            throw OrganizationNotFoundException::forId($command->organizationId);
        }

        if (!Uuid::isValid($command->userId)) {
            throw MemberNotFoundException::of($command->userId, $command->organizationId);
        }

        $organization->changeMemberRole($actorId, Uuid::fromString($command->userId), MembershipRole::from($command->role));
        $this->organizations->save($organization);
    }
}
