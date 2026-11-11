<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\CreateOrganization;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Creates an organization with the given user as its owner. */
final readonly class CreateOrganizationHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private UserRepositoryInterface $users,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateOrganizationCommand $command): void
    {
        $owner = $this->users->findById(Uuid::fromString($command->ownerId))
            ?? throw UserNotFoundException::forId($command->ownerId);

        $organization = new Organization(
            Uuid::fromString($command->organizationId),
            $command->name,
            $owner,
            $this->clock->now(),
            $command->taxId,
            $command->countryCode,
            $command->currency,
            $command->timezone,
        );

        $this->organizations->add($organization);
    }
}
