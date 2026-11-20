<?php

declare(strict_types=1);

namespace Ui\Http\Ksef\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ksef\Application\Command\SaveKsefToken\SaveKsefTokenCommand;
use App\Ksef\Application\Command\TestKsefConnection\TestKsefConnectionCommand;
use App\Ksef\Application\Query\GetKsefConnection\GetKsefConnectionQuery;
use App\Ksef\Application\Query\GetKsefConnection\KsefConnectionView;
use App\Ksef\Application\Query\GetKsefTokenTarget\GetKsefTokenTargetQuery;
use App\Ksef\Application\Query\GetKsefTokenTarget\KsefTokenTargetView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Secret\Application\Command\SetOrganizationSecret\SetOrganizationSecretCommand;
use App\Secret\Domain\Model\SecretKind;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Ksef\KsefConnectionResource;
use Ui\Http\Ksef\SaveKsefTokenInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner saves the organization's KSeF token (ADR 0026 §5): the KSeF module first checks that the
 * owner may and that KSeF is available to the organization, the token goes encrypted to the Secret
 * module (replacing the existing secret in place), the connection records it, and the token is
 * checked against KSeF so the response shows whether it works.
 *
 * @implements ProcessorInterface<SaveKsefTokenInput, KsefConnectionResource>
 */
final readonly class SaveKsefTokenProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): KsefConnectionResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();

        /** @var KsefTokenTargetView|null $target */
        $target = $this->queryBus->ask(new GetKsefTokenTargetQuery($organizationId, $actorId, toSave: true));
        $target ?? throw OrganizationNotFoundException::forId($organizationId);
        $secretId = $target->secretId ?? Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new SetOrganizationSecretCommand($secretId, $organizationId, SecretKind::KsefToken->value, $data->token, $actorId));
        $this->commandBus->handle(new SaveKsefTokenCommand($organizationId, $actorId, $target->connectionId ?? Uuid::v7()->toRfc4122(), $secretId));
        $this->commandBus->handle(new TestKsefConnectionCommand($organizationId, $actorId));

        /** @var KsefConnectionView $view */
        $view = $this->queryBus->ask(new GetKsefConnectionQuery($organizationId, $actorId));

        return KsefConnectionResource::fromView($view);
    }
}
