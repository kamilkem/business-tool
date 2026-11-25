<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoiceNumbers;

use App\Shared\Application\Query\QueryInterface;

/**
 * For Purchasing showing where a purchased item comes from (#242): the invoice numbers of some of the
 * current organization's invoices. The answer is an array<string, string>, the number by invoice id,
 * with only the invoices that exist there and have a number (a receipt has none). The caller checks
 * who may see it; the number is not copied into Purchasing because Expenses may correct it.
 */
final readonly class GetPurchaseInvoiceNumbersQuery implements QueryInterface
{
    public const int MAX_INVOICES = 500;

    /** @param list<string> $invoiceIds at most MAX_INVOICES */
    public function __construct(
        public string $organizationId,
        public array $invoiceIds,
    ) {
    }
}
