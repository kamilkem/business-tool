<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Repository;

use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use Symfony\Component\Uid\Uuid;

/** Purchase invoices of the current organization only (TenantFilter), with their VAT lines, never their documents. */
interface PurchaseInvoiceRepositoryInterface
{
    public function findById(Uuid $id): ?PurchaseInvoice;

    public function findByExternalId(PurchaseInvoiceSource $source, string $externalId): ?PurchaseInvoice;

    /**
     * Which of these ids in the source are stored.
     *
     * @param list<string> $externalIds
     *
     * @return list<string>
     */
    public function findStoredExternalIds(PurchaseInvoiceSource $source, array $externalIds): array;

    /**
     * The invoices imported from the source that have a document and no items, by id, after `$after`
     * (keyset paging), at most `$limit`: what an importer may read again (#230).
     *
     * @return list<Uuid>
     */
    public function findImportedIdsWithDocumentAndNoItems(PurchaseInvoiceSource $source, ?Uuid $after, int $limit): array;

    /**
     * The invoices that have items, with their items, by id, after `$after` (keyset paging), at most
     * `$limit`: what Purchasing copies (#240).
     *
     * @return list<PurchaseInvoice>
     */
    public function findWithItemsAfter(?Uuid $after, int $limit): array;

    /**
     * The ids of the organizations, all of them, that have an invoice with items, by id.
     *
     * @return list<string>
     */
    public function findOrganizationIdsWithItems(): array;

    /**
     * The invoices the filter selects, newest issue date first, then by seller name and invoice number.
     *
     * @return list<PurchaseInvoice>
     */
    public function findByFilter(PurchaseInvoiceFilter $filter): array;

    /**
     * Stores a new invoice and, when given, its original document, both or neither.
     *
     * @throws PurchaseInvoiceAlreadyImportedException when the source's id is already stored (the unique constraint caught it)
     * @throws \InvalidArgumentException               when the document belongs to another invoice
     */
    public function add(PurchaseInvoice $invoice, ?PurchaseInvoiceDocument $document = null): void;

    /** Persists changes made to an already managed invoice. */
    public function save(PurchaseInvoice $invoice): void;

    /** Deletes an invoice with its VAT lines (and its document, if it has one). */
    public function remove(PurchaseInvoice $invoice): void;
}
