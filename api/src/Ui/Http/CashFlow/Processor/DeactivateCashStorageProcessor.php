<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\DeactivateCashStorage\DeactivateCashStorageCommand;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use App\CashFlow\Application\Query\GetCashStorage\GetCashStorageQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\CashStorageResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deactivates a cash storage of the organization selected by the X-Organization-Id
 * header (ADR 0033 §3).
 *
 * @implements ProcessorInterface<null, CashStorageResource>
 */
final readonly class DeactivateCashStorageProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CashStorageResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $storageId = $uriVariables['id'];
        \assert(\is_string($storageId));

        $this->commandBus->handle(new DeactivateCashStorageCommand($organizationId, $actorId, $storageId));

        /** @var CashStorageView $view */
        $view = $this->queryBus->ask(new GetCashStorageQuery($organizationId, $actorId, $storageId));

        return CashStorageResource::fromView($view);
    }
}
