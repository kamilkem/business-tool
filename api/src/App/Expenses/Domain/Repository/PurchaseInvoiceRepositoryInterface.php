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
     * The invoices the filter selects, newest issue date first, then by seller name and invoice number.
     *
     * @return list<PurchaseInvoice>
     */
    public function findByFilter(PurchaseInvoiceFilter $filter): array;

    /**
     * The category owners and managers chose most often for one seller's invoices (#55): by its tax
     * ID key when given, else by its name key (SellerKey). Suggested categories nobody confirmed and
     * archived categories do not count. A tie goes to the category of the seller's latest invoice by
     * issue date (then by when it was stored). Null when there is none.
     */
    public function findMostUsedCategoryIdOfSeller(?string $sellerTaxIdKey, string $sellerNameKey): ?Uuid;

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
