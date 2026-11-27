<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductPackageSizes;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\PackageSize\PackageSizeGroup;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetProductPackageSizesHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private PurchasedItemRepositoryInterface $items,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws PurchasingManagementForbiddenException when the actor is an employee */
    public function __invoke(GetProductPackageSizesQuery $query): ?ProductPackageSizesView
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }
        $product = Uuid::isValid($query->productId) ? $this->products->findById(Uuid::fromString($query->productId)) : null;
        if (null === $product) {
            return null;
        }

        return new ProductPackageSizesView($product->getId()->toRfc4122(), PackageSizeGroup::of($this->items->countPackageSizesOfProduct($product->getId())));
    }
}
