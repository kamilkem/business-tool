<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\ChangeCashStorage\ChangeCashStorageCommand;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use App\CashFlow\Application\Query\GetCashStorage\GetCashStorageQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\CashStorageResource;
use Ui\Http\CashFlow\UpdateCashStorageInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager renames a cash storage of the organization selected by the X-Organization-Id
 * header or changes its mode (ADR 0033 §3).
 *
 * @implements ProcessorInterface<UpdateCashStorageInput, CashStorageResource>
 */
final readonly class UpdateCashStorageProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateCashStorageInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CashStorageResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $storageId = $uriVariables['id'];
        \assert(\is_string($storageId) && null !== $data->name && null !== $data->mode);

        $this->commandBus->handle(new ChangeCashStorageCommand($organizationId, $actorId, $storageId, $data->name, $data->mode));

        /** @var CashStorageView $view */
        $view = $this->queryBus->ask(new GetCashStorageQuery($organizationId, $actorId, $storageId));

        return CashStorageResource::fromView($view);
    }
}
