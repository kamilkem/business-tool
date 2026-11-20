<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\RemoveKsefToken;

use App\Ksef\Application\KsefActor;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;

final readonly class RemoveKsefTokenHandler implements CommandHandlerInterface
{
    public function __construct(
        private KsefConnectionRepositoryInterface $connections,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws KsefConnectionForbiddenException when the actor is not an owner
     * @throws KsefConnectionNotFoundException  when the organization has no KSeF connection
     */
    public function __invoke(RemoveKsefTokenCommand $command): void
    {
        $actor = KsefActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        $connection = $this->connections->findCurrent() ?? throw KsefConnectionNotFoundException::create();

        $connection->tokenRemoved($actor->id, $actor->role);
        $this->connections->save($connection);
    }
}
