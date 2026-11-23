<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

/**
 * How invoices of the same seller are recognized (#55): by the tax ID without spaces or dashes and
 * upper-cased ("526-025-09-95" and "5260250995" are one seller), or, for a seller without one, by the
 * name trimmed and lower-cased. PurchaseInvoice stores both keys, so the seller's history is found by
 * equality.
 */
final class SellerKey
{
    private function __construct()
    {
    }

    /** Null when there is no tax ID, or nothing but spaces and dashes. */
    public static function ofTaxId(?string $taxId): ?string
    {
        if (null === $taxId) {
            return null;
        }
        $key = strtoupper(str_replace([' ', '-'], '', trim($taxId)));

        return '' === $key ? null : $key;
    }

    /** Empty for a blank name, which no stored invoice has. */
    public static function ofName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
