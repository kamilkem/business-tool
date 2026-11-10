<?php

declare(strict_types=1);

namespace App\Organization\Application\Command\UpdateOrganization;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateOrganizationHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    public function __invoke(UpdateOrganizationCommand $command): void
    {
        $actorId = Uuid::fromString($command->actorId);
        $organization = $this->organizations->findById(Uuid::fromString($command->organizationId));

        if (null === $organization || null === $organization->membershipOf($actorId)) {
            throw OrganizationNotFoundException::forId($command->organizationId);
        }

        $organization->changeSettings(
            $actorId,
            $command->name,
            $command->taxId,
            $command->countryCode,
            $command->currency,
            $command->timezone,
        );

        $this->organizations->save($organization);
    }
}
