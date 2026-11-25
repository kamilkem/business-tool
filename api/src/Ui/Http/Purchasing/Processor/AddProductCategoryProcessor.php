<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\AddProductCategory\AddProductCategoryCommand;
use App\Purchasing\Application\Query\GetProductCategory\GetProductCategoryQuery;
use App\Purchasing\Application\Query\GetProductCategory\ProductCategoryView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Purchasing\AddProductCategoryInput;
use Ui\Http\Purchasing\ProductCategoryResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager adds a product category to the organization selected by the
 * X-Organization-Id header.
 *
 * @implements ProcessorInterface<AddProductCategoryInput, ProductCategoryResource>
 */
final readonly class AddProductCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AddProductCategoryInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductCategoryResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $categoryId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new AddProductCategoryCommand($organizationId, $actorId, $categoryId, $data->name, $data->parentId));

        /** @var ProductCategoryView $view */
        $view = $this->queryBus->ask(new GetProductCategoryQuery($organizationId, $actorId, $categoryId));

        return ProductCategoryResource::fromView($view);
    }
}
