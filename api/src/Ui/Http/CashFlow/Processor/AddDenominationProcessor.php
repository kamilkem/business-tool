<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\AddDenomination\AddDenominationCommand;
use App\CashFlow\Application\Query\GetDenomination\DenominationView;
use App\CashFlow\Application\Query\GetDenomination\GetDenominationQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\CashFlow\AddDenominationInput;
use Ui\Http\CashFlow\DenominationResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager adds a denomination in the currency of the organization selected by the
 * X-Organization-Id header (ADR 0033 §2).
 *
 * @implements ProcessorInterface<AddDenominationInput, DenominationResource>
 */
final readonly class AddDenominationProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AddDenominationInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DenominationResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $denominationId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new AddDenominationCommand($organizationId, $actorId, $denominationId, $data->value, $data->kind));

        /** @var DenominationView $view */
        $view = $this->queryBus->ask(new GetDenominationQuery($organizationId, $actorId, $denominationId));

        return DenominationResource::fromView($view);
    }
}
