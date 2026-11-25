<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\UpdateProduct\UpdateProductCommand;
use App\Purchasing\Application\Query\GetProduct\GetProductQuery;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\ProductResource;
use Ui\Http\Purchasing\UpdateProductInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager changes a product of the organization selected by the X-Organization-Id header.
 *
 * @implements ProcessorInterface<UpdateProductInput, ProductResource>
 */
final readonly class UpdateProductProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateProductInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $productId = $uriVariables['id'];
        \assert(\is_string($productId) && null !== $data->name && null !== $data->measure && null !== $data->excluded);

        $this->commandBus->handle(new UpdateProductCommand(
            $organizationId,
            $actorId,
            $productId,
            $data->name,
            $data->categoryId,
            $data->measure->value,
            $data->referenceQuantity,
            $data->excluded,
        ));

        /** @var ProductView $view */
        $view = $this->queryBus->ask(new GetProductQuery($organizationId, $actorId, $productId));

        return ProductResource::fromView($view);
    }
}
