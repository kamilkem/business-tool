<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\UpdateProductCategory\UpdateProductCategoryCommand;
use App\Purchasing\Application\Query\GetProductCategory\GetProductCategoryQuery;
use App\Purchasing\Application\Query\GetProductCategory\ProductCategoryView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\ProductCategoryResource;
use Ui\Http\Purchasing\UpdateProductCategoryInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager renames a product category of the organization selected by the
 * X-Organization-Id header and moves it in the tree.
 *
 * @implements ProcessorInterface<UpdateProductCategoryInput, ProductCategoryResource>
 */
final readonly class UpdateProductCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateProductCategoryInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductCategoryResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $categoryId = $uriVariables['id'];
        \assert(\is_string($categoryId) && null !== $data->name);

        $this->commandBus->handle(new UpdateProductCategoryCommand($organizationId, $actorId, $categoryId, $data->name, $data->parentId));

        /** @var ProductCategoryView $view */
        $view = $this->queryBus->ask(new GetProductCategoryQuery($organizationId, $actorId, $categoryId));

        return ProductCategoryResource::fromView($view);
    }
}
