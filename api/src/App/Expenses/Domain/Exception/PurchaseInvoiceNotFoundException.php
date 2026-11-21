<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/** No such purchase invoice in this organization (another organization's is not found either). */
final class PurchaseInvoiceNotFoundException extends ExpensesException
{
    public static function withId(string $invoiceId): self
    {
        return new self(\sprintf('Purchase invoice "%s" not found.', $invoiceId), ['invoiceId' => $invoiceId]);
    }
}
