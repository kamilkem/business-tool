<?php

declare(strict_types=1);

namespace App\Secret\Application\Command\RemoveOrganizationSecret;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Secret\Application\SecretActor;
use App\Secret\Domain\Event\OrganizationSecretRemoved;
use App\Secret\Domain\Exception\OrganizationSecretNotFoundException;
use App\Secret\Domain\Exception\SecretManagementForbiddenException;
use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RemoveOrganizationSecretHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationSecretRepositoryInterface $secrets,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException       when the actor is not an active member of the organization
     * @throws SecretManagementForbiddenException  when the actor is not an owner
     * @throws OrganizationSecretNotFoundException when the secret is not one of the organization's
     */
    public function __invoke(RemoveOrganizationSecretCommand $command): void
    {
        $actor = SecretActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $secret = Uuid::isValid($command->secretId) ? $this->secrets->findById(Uuid::fromString($command->secretId)) : null;
        $secret ??= throw OrganizationSecretNotFoundException::withId($command->secretId);

        $secret->assertCanBeRemovedBy($actor->id, $actor->role);
        $kind = $secret->getKind()->value;
        $this->secrets->remove($secret);

        $this->events->publish(new OrganizationSecretRemoved($command->organizationId, $command->secretId, $kind, $command->actorId));
        $this->logger->info('Organization secret removed.', [
            'organization_id' => $command->organizationId,
            'secret_id' => $command->secretId,
            'kind' => $kind,
            'actor_id' => $command->actorId,
        ]);
    }
}
