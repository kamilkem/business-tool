<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\CreateProduct\CreateProductCommand;
use App\Purchasing\Application\Query\GetProduct\GetProductQuery;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Purchasing\CreateProductInput;
use Ui\Http\Purchasing\ProductResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager creates a product in the organization selected by the X-Organization-Id header.
 *
 * @implements ProcessorInterface<CreateProductInput, ProductResource>
 */
final readonly class CreateProductProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param CreateProductInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $productId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new CreateProductCommand(
            $organizationId,
            $actorId,
            $productId,
            $data->name,
            $data->measure->value,
            $data->referenceQuantity,
            $data->categoryId,
            $data->excluded,
        ));

        /** @var ProductView $view */
        $view = $this->queryBus->ask(new GetProductQuery($organizationId, $actorId, $productId));

        return ProductResource::fromView($view);
    }
}
