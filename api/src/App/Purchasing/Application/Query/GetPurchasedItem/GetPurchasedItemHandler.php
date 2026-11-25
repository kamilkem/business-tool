<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetPurchasedItem;

use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetPurchasedItemHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private PurchasedItemViews $views,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws PurchasingManagementForbiddenException when the actor is an employee */
    public function __invoke(GetPurchasedItemQuery $query): ?PurchasedItemView
    {
        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $item = Uuid::isValid($query->itemId) ? $this->items->findById(Uuid::fromString($query->itemId)) : null;

        return null === $item ? null : $this->views->of($query->organizationId, [$item])[0];
    }
}
