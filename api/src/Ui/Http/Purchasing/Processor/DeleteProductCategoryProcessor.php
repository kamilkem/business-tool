<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\DeleteProductCategory\DeleteProductCategoryCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes a product category of the organization selected by the
 * X-Organization-Id header that has no subcategory and no product (ADR 0041 §5).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteProductCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $categoryId = $uriVariables['id'];
        \assert(\is_string($categoryId));

        $this->commandBus->handle(new DeleteProductCategoryCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            categoryId: $categoryId,
        ));

        return null;
    }
}
