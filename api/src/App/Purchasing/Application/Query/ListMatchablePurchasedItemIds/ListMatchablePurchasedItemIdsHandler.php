<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListMatchablePurchasedItemIds;

use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListMatchablePurchasedItemIdsHandler implements QueryHandlerInterface
{
    public function __construct(private PurchasedItemRepositoryInterface $items)
    {
    }

    /** @return list<string> */
    public function __invoke(ListMatchablePurchasedItemIdsQuery $query): array
    {
        $after = null !== $query->afterItemId && Uuid::isValid($query->afterItemId) ? Uuid::fromString($query->afterItemId) : null;

        return array_map(
            static fn (Uuid $id): string => $id->toRfc4122(),
            $this->items->findMatchableIds(MatchScope::from($query->scope), $after, $query->limit, null === $query->askedBefore ? null : new \DateTimeImmutable($query->askedBefore)),
        );
    }
}
