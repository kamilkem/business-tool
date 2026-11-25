<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProduct;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetProductHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws PurchasingManagementForbiddenException when the actor is an employee */
    public function __invoke(GetProductQuery $query): ?ProductView
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $product = Uuid::isValid($query->productId) ? $this->products->findById(Uuid::fromString($query->productId)) : null;

        return null === $product ? null : ProductView::fromProduct($product);
    }
}
