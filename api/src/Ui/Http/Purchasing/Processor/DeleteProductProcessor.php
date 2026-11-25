<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\DeleteProduct\DeleteProductCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes a product of the organization selected by the X-Organization-Id
 * header that no purchased item refers to (ADR 0041 §4).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteProductProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $productId = $uriVariables['id'];
        \assert(\is_string($productId));

        $this->commandBus->handle(new DeleteProductCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            productId: $productId,
        ));

        return null;
    }
}
