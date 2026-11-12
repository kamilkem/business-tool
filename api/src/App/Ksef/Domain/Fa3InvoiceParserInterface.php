<?php

declare(strict_types=1);

namespace App\Ksef\Domain;

use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Ksef\Domain\Exception\InvalidFa3InvoiceException;
use App\Ksef\Domain\Exception\UnsupportedInvoiceFormException;

/**
 * Reads an FA(3) invoice, as `GET /invoices/ksef/{ksefNumber}` returns it, into Expenses' neutral
 * purchase invoice data (ADR 0023 §7, ADR 0024 §3). Every amount comes from the XML's decimal
 * strings, in minor units (ADR 0010); the issue date is a business date (ADR 0017).
 *
 * The result is what the invoice says; Expenses checks it again when it stores it.
 */
interface Fa3InvoiceParserInterface
{
    /**
     * @throws UnsupportedInvoiceFormException the document is well-formed XML but not FA(3) (`FA (3)`, schema `1-0E`)
     * @throws InvalidFa3InvoiceException      the document is not XML, or an FA(3) field the import needs is missing or unreadable
     */
    public function parse(string $xml): PurchaseInvoiceDetails;
}
