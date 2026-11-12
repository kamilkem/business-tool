<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/** An external id is stored at most once per source and organization, which makes an import idempotent (ADR 0024). */
final class PurchaseInvoiceAlreadyImportedException extends ExpensesException
{
    public static function for(string $source, string $externalId): self
    {
        return new self(\sprintf('The invoice "%s" from "%s" is already stored.', $externalId, $source));
    }
}
