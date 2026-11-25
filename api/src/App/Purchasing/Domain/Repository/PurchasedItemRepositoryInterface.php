<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Model\PurchasedItem;
use Symfony\Component\Uid\Uuid;

/** Purchased items of the current organization only (TenantFilter). */
interface PurchasedItemRepositoryInterface
{
    /**
     * The items recorded for an invoice, by index.
     *
     * @return list<PurchasedItem>
     */
    public function findByInvoice(Uuid $invoiceId): array;

    /**
     * Stores new items in one flush: all or none. The unique constraint on (invoice, item index) refuses
     * an item recorded in the meantime, with a unique constraint violation.
     *
     * @param list<PurchasedItem> $items
     */
    public function addAll(array $items): void;

    /** Whether an item is linked to the product. */
    public function hasItemsOfProduct(Uuid $productId): bool;

    /**
     * Links every item of one product to another, keeping each item's status and package size: the
     * move of a merge (ADR 0041 §4). One statement; the products must be the current organization's.
     */
    public function moveItems(Uuid $fromProductId, Uuid $toProductId): void;
}
