<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\TimeTracking\Application\Command\DeleteShift\DeleteShiftCommand;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes a shift of the organization selected by the X-Organization-Id header;
 * no trace is kept (#28).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteShiftProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $shiftId = $uriVariables['id'];
        \assert(\is_string($shiftId));

        $this->commandBus->handle(new DeleteShiftCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            shiftId: $shiftId,
        ));

        return null;
    }
}
