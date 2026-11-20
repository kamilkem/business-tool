<?php

declare(strict_types=1);

namespace App\Secret\Application\Command\SetOrganizationSecret;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Secret\Application\SecretActor;
use App\Secret\Domain\Event\OrganizationSecretSet;
use App\Secret\Domain\Exception\SecretManagementForbiddenException;
use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Model\SecretKind;
use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use App\Secret\Domain\SecretCipherInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SetOrganizationSecretHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrganizationSecretRepositoryInterface $secrets,
        private SecretCipherInterface $cipher,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws SecretManagementForbiddenException when the actor is not an owner
     */
    public function __invoke(SetOrganizationSecretCommand $command): void
    {
        $actor = SecretActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        $organizationId = Uuid::fromString($command->organizationId);
        $secretId = Uuid::fromString($command->secretId);
        $kind = SecretKind::from($command->kind);
        $now = $this->clock->now();

        $value = $this->cipher->encrypt($command->value, OrganizationSecret::associatedData($organizationId, $secretId, $kind));

        $secret = $this->secrets->findById($secretId);
        if (null === $secret) {
            $secret = OrganizationSecret::create($secretId, $kind, $value, $actor->id, $actor->role, $now);
            $this->secrets->add($secret);
        } else {
            $secret->replace($value, $actor->id, $actor->role, $now);
            $this->secrets->save($secret);
        }

        $this->events->publish(new OrganizationSecretSet($command->organizationId, $command->secretId, $kind->value, $value->keyId, $command->actorId));
        $this->logger->info('Organization secret set.', [
            'organization_id' => $command->organizationId,
            'secret_id' => $command->secretId,
            'kind' => $kind->value,
            'key_id' => $value->keyId,
            'actor_id' => $command->actorId,
        ]);
    }
}
