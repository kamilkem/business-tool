<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListConfirmedPurchasedItemIds;

use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListConfirmedPurchasedItemIdsHandler implements QueryHandlerInterface
{
    public function __construct(private PurchasedItemRepositoryInterface $items)
    {
    }

    /** @return list<string> */
    public function __invoke(ListConfirmedPurchasedItemIdsQuery $query): array
    {
        $after = null !== $query->afterItemId && Uuid::isValid($query->afterItemId) ? Uuid::fromString($query->afterItemId) : null;

        return array_map(
            static fn (Uuid $id): string => $id->toRfc4122(),
            $this->items->findConfirmedIds($after, $query->limit),
        );
    }
}
