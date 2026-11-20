<?php

declare(strict_types=1);

namespace Ui\Http\Ksef\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ksef\Application\Command\RemoveKsefToken\RemoveKsefTokenCommand;
use App\Ksef\Application\Query\GetKsefTokenTarget\GetKsefTokenTargetQuery;
use App\Ksef\Application\Query\GetKsefTokenTarget\KsefTokenTargetView;
use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Secret\Application\Command\RemoveOrganizationSecret\RemoveOrganizationSecretCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner removes the organization's KSeF token: the secret is deleted, then the connection is
 * disabled. Allowed whatever the organization's country, so a token can always be taken out.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class RemoveKsefTokenProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();

        /** @var KsefTokenTargetView|null $target */
        $target = $this->queryBus->ask(new GetKsefTokenTargetQuery($organizationId, $actorId, toSave: false));
        $secretId = $target->secretId ?? throw KsefConnectionNotFoundException::create();

        $this->commandBus->handle(new RemoveOrganizationSecretCommand($organizationId, $actorId, $secretId));
        $this->commandBus->handle(new RemoveKsefTokenCommand($organizationId, $actorId));

        return null;
    }
}
