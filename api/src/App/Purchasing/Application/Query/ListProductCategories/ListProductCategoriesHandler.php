<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListProductCategories;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Application\Query\GetProductCategory\ProductCategoryView;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListProductCategoriesHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<ProductCategoryView>|null by name
     *
     * @throws PurchasingManagementForbiddenException when the actor is an employee
     */
    public function __invoke(ListProductCategoriesQuery $query): ?array
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        return array_map(ProductCategoryView::fromCategory(...), $this->categories->findAll());
    }
}
