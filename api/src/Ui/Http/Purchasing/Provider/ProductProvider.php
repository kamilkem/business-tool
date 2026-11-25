<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetProduct\GetProductQuery;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\ProductResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * One product of the organization selected by the X-Organization-Id header. An employee is refused
 * by the domain (PurchasingAccess); another organization's product is a 404.
 *
 * @implements ProviderInterface<ProductResource>
 */
final readonly class ProductProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $productId = $uriVariables['id'];
        \assert(\is_string($productId));

        /** @var ProductView|null $view */
        $view = $this->queryBus->ask(new GetProductQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $productId,
        ));

        return ProductResource::fromView($view ?? throw ProductNotFoundException::withId($productId));
    }
}
