<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetProductCategory\ProductCategoryView;
use App\Purchasing\Application\Query\ListProductCategories\ListProductCategoriesQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\ProductCategoryResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The product categories of the organization selected by the X-Organization-Id header, flat.
 * Employees are refused by the domain (PurchasingAccess).
 *
 * @implements ProviderInterface<ProductCategoryResource>
 */
final readonly class ProductCategoryCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<ProductCategoryResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var list<ProductCategoryView>|null $views */
        $views = $this->queryBus->ask(new ListProductCategoriesQuery($organizationId, $this->currentUser->id()));

        return array_map(
            ProductCategoryResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
