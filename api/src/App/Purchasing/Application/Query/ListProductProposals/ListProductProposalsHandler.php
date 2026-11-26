<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListProductProposals;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Proposal\ProposalGrouper;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListProductProposalsHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductRepositoryInterface $products,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<ProductProposalView>|null the largest first
     *
     * @throws PurchasingManagementForbiddenException when the actor is an employee
     */
    public function __invoke(ListProductProposalsQuery $query): ?array
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $groups = ProposalGrouper::group($this->items->findProposed());
        if ([] === $groups) {
            return [];
        }

        $byName = [];
        foreach ($this->products->search(null, null) as $product) {
            $byName[Product::nameKeyOf($product->getName())] = $product->getId()->toRfc4122();
        }

        return array_map(
            static fn ($group): ProductProposalView => ProductProposalView::fromGroup($group, $byName[Product::nameKeyOf($group->name)] ?? null),
            $groups,
        );
    }
}
