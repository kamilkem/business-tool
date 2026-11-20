<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Repository;

use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use Symfony\Component\Uid\Uuid;

/**
 * Original documents of the current organization's purchase invoices (TenantFilter), read one at a
 * time. They are stored with their invoice (PurchaseInvoiceRepositoryInterface::add()) and never changed.
 */
interface PurchaseInvoiceDocumentRepositoryInterface
{
    public function findByInvoiceId(Uuid $invoiceId): ?PurchaseInvoiceDocument;

    /**
     * The formats of the given invoices' documents, without loading their content.
     *
     * @param list<Uuid> $invoiceIds
     *
     * @return array<string, string> format by invoice id (RFC 4122), only for invoices that have a document
     */
    public function findFormatsByInvoiceIds(array $invoiceIds): array;
}
