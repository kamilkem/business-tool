<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetProductPackageSizes\GetProductPackageSizesQuery;
use App\Purchasing\Application\Query\GetProductPackageSizes\ProductPackageSizesView;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\ProductPackageSizesResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The items of a product of the organization selected by the X-Organization-Id header by unit and package size.
 * An employee is refused by the domain (PurchasingAccess); another organization's product is a 404.
 *
 * @implements ProviderInterface<ProductPackageSizesResource>
 */
final readonly class ProductPackageSizesProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductPackageSizesResource
    {
        $productId = $uriVariables['id'];
        \assert(\is_string($productId));

        /** @var ProductPackageSizesView|null $view */
        $view = $this->queryBus->ask(new GetProductPackageSizesQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $productId,
        ));

        return ProductPackageSizesResource::fromView($view ?? throw ProductNotFoundException::withId($productId));
    }
}
