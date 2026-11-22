<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\DeleteRegister\DeleteRegisterCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes a register of the organization selected by the X-Organization-Id
 * header that no revenue entry refers to (ADR 0032).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteRegisterProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $registerId = $uriVariables['id'];
        \assert(\is_string($registerId));

        $this->commandBus->handle(new DeleteRegisterCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            registerId: $registerId,
        ));

        return null;
    }
}
