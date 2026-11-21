<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/** The purchase invoice has no original document (e.g. it was entered by hand), or is not the organization's. */
final class PurchaseInvoiceDocumentNotFoundException extends ExpensesException
{
    public static function forInvoice(string $invoiceId): self
    {
        return new self(\sprintf('Purchase invoice "%s" has no original document.', $invoiceId), ['invoiceId' => $invoiceId]);
    }
}
