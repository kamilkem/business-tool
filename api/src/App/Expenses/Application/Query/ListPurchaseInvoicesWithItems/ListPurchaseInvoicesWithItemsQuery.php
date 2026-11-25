<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoicesWithItems;

use App\Shared\Application\Query\QueryInterface;

/**
 * For Purchasing copying the items stored before it existed (#240, ADR 0041 §2): the current
 * organization's invoices that have items, by id, after `$afterInvoiceId` (keyset paging), at most
 * `$limit`, each with all its items. The answer is a list<PurchaseInvoiceWithItemsView>. An invoice
 * id is a UUID v7, so the order is also the order the invoices were stored in.
 */
final readonly class ListPurchaseInvoicesWithItemsQuery implements QueryInterface
{
    public const int MAX_LIMIT = 200;

    public function __construct(
        public string $organizationId,
        public ?string $afterInvoiceId,
        public int $limit,
    ) {
    }
}
