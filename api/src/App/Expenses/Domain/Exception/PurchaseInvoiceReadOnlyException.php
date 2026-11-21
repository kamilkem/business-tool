<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/**
 * An imported invoice says what its source says: it cannot be edited or deleted, only categorized
 * (ADR 0024, #54). Only an invoice entered by hand (source manual) can.
 */
final class PurchaseInvoiceReadOnlyException extends ExpensesException
{
    public static function imported(string $invoiceId, string $source): self
    {
        return new self(\sprintf('The expense "%s" was imported from "%s": only its category can be changed.', $invoiceId, $source), ['invoiceId' => $invoiceId, 'source' => $source]);
    }
}
