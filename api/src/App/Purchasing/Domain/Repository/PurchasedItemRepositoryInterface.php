<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Repository;

use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Proposal\ProposedItem;
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

    public function findById(Uuid $id): ?PurchasedItem;

    /**
     * The items with these ids that are the current organization's, in any order: an id that is
     * not one of them is left out.
     *
     * @param list<Uuid> $ids
     *
     * @return list<PurchasedItem>
     */
    public function findByIds(array $ids): array;

    /**
     * The items the filter selects, newest issue date first, then by seller name, invoice and index in
     * the document, so that pages do not overlap; with their products.
     *
     * @return list<PurchasedItem>
     */
    public function search(PurchasedItemFilter $filter, int $offset, int $limit): array;

    /** How many items the filter selects. */
    public function countMatching(PurchasedItemFilter $filter): int;

    /** Persists changes made to an already managed item. */
    public function save(PurchasedItem $item): void;

    /**
     * Persists changes made to already managed items in one flush: all or none.
     *
     * @param list<PurchasedItem> $items
     */
    public function saveAll(array $items): void;

    /**
     * The ids of the items matching may change (not `confirmed`, not taken off their product by a
     * person) within the scope, by id, after `$afterId` when given.
     *
     * @return list<Uuid>
     */
    public function findMatchableIds(MatchScope $scope, ?Uuid $afterId, int $limit): array;

    /**
     * The ids of the confirmed items that have a product (the owner's word), newest invoice issue date
     * first, then by id descending, after `$afterId` in that order when given: for learning the aliases of
     * items confirmed before they were learned (ADR 0045 §3).
     *
     * @return list<Uuid>
     */
    public function findConfirmedIds(?Uuid $afterId, int $limit): array;

    /**
     * The prices per base unit (PriceSanity::perBaseUnit()) of a product's most recent confirmed items in a
     * currency, up to `$limit`: plain purchases only (no correction), with a net value, a quantity and a
     * package size. For the price check (ADR 0045 §2).
     *
     * @return list<int>
     */
    public function confirmedPricesPerBaseUnit(Uuid $productId, string $currency, int $limit): array;

    /**
     * The items that carry a proposal for a product (ADR 0044) and are still open to it: unassigned, not
     * taken off a product by a person. By item name, then id; only scalars are read.
     *
     * @return list<ProposedItem>
     */
    public function findProposed(): array;

    /**
     * The items of one proposal, by the key of the proposed name (the same open items as findProposed()).
     *
     * @return list<PurchasedItem>
     */
    public function findByProposalKey(string $key): array;

    /** Whether an item is linked to the product. */
    public function hasItemsOfProduct(Uuid $productId): bool;

    /**
     * Links every item of one product to another, keeping each item's status and package size: the
     * move of a merge (ADR 0041 §4). One statement; the products must be the current organization's.
     */
    public function moveItems(Uuid $fromProductId, Uuid $toProductId): void;
}
