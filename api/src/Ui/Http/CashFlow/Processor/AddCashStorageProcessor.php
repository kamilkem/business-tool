<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\AddCashStorage\AddCashStorageCommand;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use App\CashFlow\Application\Query\GetCashStorage\GetCashStorageQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\CashFlow\AddCashStorageInput;
use Ui\Http\CashFlow\CashStorageResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager adds a cash storage to the organization selected by the X-Organization-Id
 * header (ADR 0033 §3).
 *
 * @implements ProcessorInterface<AddCashStorageInput, CashStorageResource>
 */
final readonly class AddCashStorageProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AddCashStorageInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CashStorageResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $storageId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new AddCashStorageCommand($organizationId, $actorId, $storageId, $data->name, $data->mode));

        /** @var CashStorageView $view */
        $view = $this->queryBus->ask(new GetCashStorageQuery($organizationId, $actorId, $storageId));

        return CashStorageResource::fromView($view);
    }
}
