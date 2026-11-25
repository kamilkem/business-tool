<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\MergeProduct\MergeProductCommand;
use App\Purchasing\Application\Query\GetProduct\GetProductQuery;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\MergeProductInput;
use Ui\Http\Purchasing\ProductResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager merges a product of the organization selected by the X-Organization-Id header
 * into another one, and gets the one that took over its items.
 *
 * @implements ProcessorInterface<MergeProductInput, ProductResource>
 */
final readonly class MergeProductProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param MergeProductInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $productId = $uriVariables['id'];
        \assert(\is_string($productId));

        $this->commandBus->handle(new MergeProductCommand($organizationId, $actorId, $productId, $data->into));

        /** @var ProductView $view */
        $view = $this->queryBus->ask(new GetProductQuery($organizationId, $actorId, $data->into));

        return ProductResource::fromView($view);
    }
}
