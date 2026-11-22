<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\DeleteCashStorage\DeleteCashStorageCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes a cash storage of the organization selected by the X-Organization-Id
 * header that no close or movement refers to (ADR 0033 §3).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteCashStorageProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $storageId = $uriVariables['id'];
        \assert(\is_string($storageId));

        $this->commandBus->handle(new DeleteCashStorageCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            storageId: $storageId,
        ));

        return null;
    }
}
