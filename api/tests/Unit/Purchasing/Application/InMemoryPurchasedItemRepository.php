<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Purchasing\Domain\Model\PurchasedItem;
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
