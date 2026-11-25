<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductCategory;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetProductCategoryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws PurchasingManagementForbiddenException when the actor is an employee */
    public function __invoke(GetProductCategoryQuery $query): ?ProductCategoryView
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $category = Uuid::isValid($query->categoryId) ? $this->categories->findById(Uuid::fromString($query->categoryId)) : null;

        return null === $category ? null : ProductCategoryView::fromCategory($category);
    }
}
