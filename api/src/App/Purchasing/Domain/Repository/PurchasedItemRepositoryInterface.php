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
}
