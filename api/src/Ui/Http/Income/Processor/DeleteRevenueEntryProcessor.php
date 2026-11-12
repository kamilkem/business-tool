<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\DeleteRevenueEntry\DeleteRevenueEntryCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes an entry of the organization selected by the X-Organization-Id header;
 * no trace is kept (ADR 0022 §8).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteRevenueEntryProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $entryId = $uriVariables['id'];
        \assert(\is_string($entryId));

        $this->commandBus->handle(new DeleteRevenueEntryCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            entryId: $entryId,
        ));

        return null;
    }
}
