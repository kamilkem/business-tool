<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListProducts;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListProductsHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<ProductView>|null by name
     *
     * @throws PurchasingManagementForbiddenException when the actor is an employee
     */
    public function __invoke(ListProductsQuery $query): ?array
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $categoryIds = null === $query->categoryId ? null : $this->subtreeOf($query->categoryId);

        return array_map(ProductView::fromProduct(...), $this->products->search($categoryIds, $query->search));
    }

    /**
     * The category and all its descendants; none for a category that does not exist. The tree of an
     * organization is small, so it is read whole.
     *
     * @return list<Uuid>
     */
    private function subtreeOf(string $categoryId): array
    {
        if (!Uuid::isValid($categoryId)) {
            return [];
        }
        $root = Uuid::fromString($categoryId);

        $childrenOf = [];
        $known = false;
        foreach ($this->categories->findAll() as $category) {
            $known = $known || $category->getId()->equals($root);
            $childrenOf[$category->getParent()?->getId()->toRfc4122() ?? ''][] = $category->getId();
        }
        if (!$known) {
            return [];
        }

        $ids = [$root];
        for ($next = 0; $next < \count($ids); ++$next) {
            foreach ($childrenOf[$ids[$next]->toRfc4122()] ?? [] as $child) {
                $ids[] = $child;
            }
        }

        return $ids;
    }
}
