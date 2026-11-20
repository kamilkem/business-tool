<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\SaveKsefToken;

use App\Ksef\Application\KsefActor;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SaveKsefTokenHandler implements CommandHandlerInterface
{
    public function __construct(
        private KsefConnectionRepositoryInterface $connections,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws KsefConnectionForbiddenException when the actor is not an owner
     * @throws KsefNotAvailableException        when the organization is not in Poland or has no valid NIP
     */
    public function __invoke(SaveKsefTokenCommand $command): void
    {
        $actor = KsefActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        $secretId = Uuid::fromString($command->secretId);

        $connection = $this->connections->findCurrent();
        if (null === $connection) {
            $connection = KsefConnection::connect(
                Uuid::fromString($command->connectionId),
                $secretId,
                $actor->countryCode,
                $actor->taxId,
                $actor->id,
                $actor->role,
                $this->clock->now(),
            );
            $this->connections->add($connection);

            return;
        }

        $connection->tokenSaved($secretId, $actor->countryCode, $actor->taxId, $actor->id, $actor->role);
        $this->connections->save($connection);
    }
}
