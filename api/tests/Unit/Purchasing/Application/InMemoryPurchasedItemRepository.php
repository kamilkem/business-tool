<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Matching\PriceSanity;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Proposal\ProposedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\PurchasedItemFilter;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryPurchasedItemRepository implements PurchasedItemRepositoryInterface
{
    /** @var array<string, PurchasedItem> by invoice id and item index */
    private array $byKey = [];

    public int $addAllCallCount = 0;

    /** @var array<string, int> items linked to a product, by product id (PurchasedItem has no way to be linked until #242) */
    private array $linkedItems = [];

    public function findByInvoice(Uuid $invoiceId): array
    {
        $items = array_values(array_filter($this->byKey, static fn (PurchasedItem $item): bool => $item->getInvoiceId()->equals($invoiceId)));
        usort($items, static fn (PurchasedItem $a, PurchasedItem $b): int => $a->getItemIndex() <=> $b->getItemIndex());

        return $items;
    }

    public function addAll(array $items): void
    {
        ++$this->addAllCallCount;
        foreach ($items as $item) {
            $key = $item->getInvoiceId()->toRfc4122().'#'.$item->getItemIndex();
            if (isset($this->byKey[$key])) {
                throw new \LogicException('The unique constraint on (invoice, item index) would refuse this.');
            }
        }
        foreach ($items as $item) {
            $this->byKey[$item->getInvoiceId()->toRfc4122().'#'.$item->getItemIndex()] = $item;
        }
    }

    public int $saveCallCount = 0;

    public function findById(Uuid $id): ?PurchasedItem
    {
        foreach ($this->byKey as $item) {
            if ($item->getId()->equals($id)) {
                return $item;
            }
        }

        return null;
    }

    public function findByIds(array $ids): array
    {
        return array_values(array_filter($this->byKey, static function (PurchasedItem $item) use ($ids): bool {
            foreach ($ids as $id) {
                if ($item->getId()->equals($id)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function search(PurchasedItemFilter $filter, int $offset, int $limit): array
    {
        return \array_slice($this->matching($filter), $offset, $limit);
    }

    public function countMatching(PurchasedItemFilter $filter): int
    {
        return \count($this->matching($filter));
    }

    public function save(PurchasedItem $item): void
    {
        ++$this->saveCallCount;
    }

    public function saveAll(array $items): void
    {
        ++$this->saveCallCount;
    }

    public function findMatchableIds(MatchScope $scope, ?Uuid $afterId, int $limit): array
    {
        $items = array_filter($this->byKey, static fn (PurchasedItem $item): bool => $item->isMatchable()
            && match ($scope) {
                MatchScope::NotYetMatched => null === $item->getMatchedAt(),
                MatchScope::Unassigned => PurchasedItemStatus::Unassigned === $item->getStatus(),
                MatchScope::AllMatchable => true,
            });
        $ids = array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), array_values($items));
        sort($ids);
        if (null !== $afterId) {
            $ids = array_values(array_filter($ids, static fn (string $id): bool => $id > $afterId->toRfc4122()));
        }

        return array_map(static fn (string $id): Uuid => Uuid::fromString($id), \array_slice($ids, 0, $limit));
    }

    public function findConfirmedIds(?Uuid $afterId, int $limit): array
    {
        $items = array_values(array_filter($this->byKey, static fn (PurchasedItem $item): bool => PurchasedItemStatus::Confirmed === $item->getStatus() && null !== $item->getProduct()));
        usort($items, static fn (PurchasedItem $a, PurchasedItem $b): int => [$b->getInvoiceIssueDate()->toString(), $b->getId()->toRfc4122()] <=> [$a->getInvoiceIssueDate()->toString(), $a->getId()->toRfc4122()]);
        $ids = array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $items);
        if (null !== $afterId) {
            $position = array_search($afterId->toRfc4122(), $ids, true);
            $ids = false === $position ? $ids : \array_slice($ids, $position + 1);
        }

        return array_map(static fn (string $id): Uuid => Uuid::fromString($id), \array_slice($ids, 0, $limit));
    }

    public function confirmedPricesPerBaseUnit(Uuid $productId, string $currency, int $limit): array
    {
        $items = array_values(array_filter($this->byKey, static fn (PurchasedItem $item): bool => PurchasedItemStatus::Confirmed === $item->getStatus()
            && ($item->getProductId()?->equals($productId) ?? false)
            && $item->getCurrency() === $currency));
        usort($items, static fn (PurchasedItem $a, PurchasedItem $b): int => [$b->getInvoiceIssueDate()->toString(), $b->getId()->toRfc4122()] <=> [$a->getInvoiceIssueDate()->toString(), $a->getId()->toRfc4122()]);

        $prices = [];
        foreach (\array_slice($items, 0, $limit) as $item) {
            $price = PriceSanity::of($item, $item->getPackageSize());
            if (null !== $price) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    public function findProposed(): array
    {
        $proposing = $this->proposing();
        usort($proposing, static fn (PurchasedItem $a, PurchasedItem $b): int => $a->getName() <=> $b->getName());

        return array_map(static fn (PurchasedItem $item): ProposedItem => new ProposedItem(
            $item->getId()->toRfc4122(),
            $item->getName(),
            (string) $item->getProposedName(),
            (string) $item->getProposedNameKey(),
            $item->getProposedMeasure() ?? throw new \LogicException('A proposal has a measure.'),
            $item->getProposedCategory()?->getId()->toRfc4122(),
        ), $proposing);
    }

    public function findByProposalKey(string $key): array
    {
        return array_values(array_filter($this->proposing(), static fn (PurchasedItem $item): bool => $item->getProposedNameKey() === $key));
    }

    /** @return list<PurchasedItem> */
    private function proposing(): array
    {
        return array_values(array_filter($this->byKey, static fn (PurchasedItem $item): bool => $item->hasProposal()
            && PurchasedItemStatus::Unassigned === $item->getStatus()
            && !$item->isMatchingSkipped()));
    }

    /** @return list<PurchasedItem> the criteria the unit tests of handlers need: statuses and product, in the order they were added */
    private function matching(PurchasedItemFilter $filter): array
    {
        return array_values(array_filter($this->byKey, static fn (PurchasedItem $item): bool => ([] === $filter->statuses || \in_array($item->getStatus(), $filter->statuses, true))
            && (null === $filter->productId || ($item->getProductId()?->equals($filter->productId) ?? false))));
    }

    /** @return list<PurchasedItem> */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    /** Arranges items linked to a product. */
    public function link(Uuid $productId, int $count = 1): void
    {
        $this->linkedItems[$productId->toRfc4122()] = ($this->linkedItems[$productId->toRfc4122()] ?? 0) + $count;
    }

    public function linkedTo(Uuid $productId): int
    {
        return $this->linkedItems[$productId->toRfc4122()] ?? 0;
    }

    public function hasItemsOfProduct(Uuid $productId): bool
    {
        return $this->linkedTo($productId) > 0;
    }

    public function moveItems(Uuid $fromProductId, Uuid $toProductId): void
    {
        $this->link($toProductId, $this->linkedTo($fromProductId));
        unset($this->linkedItems[$fromProductId->toRfc4122()]);
    }
}
