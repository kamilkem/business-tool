<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListPurchasedItems;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Application\Query\GetPurchasedItem\PurchasedItemViews;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\PurchasedItemFilter;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Symfony\Component\Uid\Uuid;

final readonly class ListPurchasedItemsHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private PurchasedItemViews $views,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws PurchasingManagementForbiddenException when the actor is an employee
     * @throws \ValueError                            for a status that is not one
     * @throws InvalidBusinessDateException           for a date that is not `Y-m-d`
     */
    public function __invoke(ListPurchasedItemsQuery $query): ?PurchasedItemPageView
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $pageSize = max(1, min($query->pageSize, ListPurchasedItemsQuery::MAX_PAGE_SIZE));
        $page = max(1, $query->page);

        // A product that cannot exist has no items.
        if (null !== $query->productId && !Uuid::isValid($query->productId)) {
            return new PurchasedItemPageView([], 0, $page, $pageSize);
        }

        $filter = new PurchasedItemFilter(
            array_map(PurchasedItemStatus::from(...), $query->statuses),
            null === $query->productId ? null : Uuid::fromString($query->productId),
            null === $query->from ? null : BusinessDate::fromString($query->from),
            null === $query->to ? null : BusinessDate::fromString($query->to),
            $query->search,
        );

        return new PurchasedItemPageView(
            $this->views->of($query->organizationId, $this->items->search($filter, ($page - 1) * $pageSize, $pageSize)),
            $this->items->countMatching($filter),
            $page,
            $pageSize,
        );
    }
}
