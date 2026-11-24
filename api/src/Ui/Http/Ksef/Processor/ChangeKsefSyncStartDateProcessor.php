<?php

declare(strict_types=1);

namespace Ui\Http\Ksef\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ksef\Application\Command\ChangeKsefSyncStartDate\ChangeKsefSyncStartDateCommand;
use App\Ksef\Application\Query\GetKsefConnection\GetKsefConnectionQuery;
use App\Ksef\Application\Query\GetKsefConnection\KsefConnectionView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Ksef\ChangeKsefSyncStartDateInput;
use Ui\Http\Ksef\KsefConnectionResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager chooses the date of issue from which KSeF purchase invoices are imported
 * (ADR 0038); the connection is returned with the new start.
 *
 * @implements ProcessorInterface<ChangeKsefSyncStartDateInput, KsefConnectionResource>
 */
final readonly class ChangeKsefSyncStartDateProcessor implements ProcessorInterface
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

        $this->commandBus->handle(new ChangeKsefSyncStartDateCommand($organizationId, $actorId, $data->syncStartDate));

        /** @var KsefConnectionView $view */
        $view = $this->queryBus->ask(new GetKsefConnectionQuery($organizationId, $actorId));

        return KsefConnectionResource::fromView($view);
    }
}
