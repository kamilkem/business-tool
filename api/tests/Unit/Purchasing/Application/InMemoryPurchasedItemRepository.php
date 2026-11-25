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
}
