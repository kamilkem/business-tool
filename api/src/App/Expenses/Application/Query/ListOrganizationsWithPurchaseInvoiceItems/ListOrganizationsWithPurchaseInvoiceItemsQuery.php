<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListOrganizationsWithPurchaseInvoiceItems;

use App\Shared\Application\Query\QueryInterface;

/**
 * The ids of the organizations, of all of them, that have an invoice with items, by id (#240). For
 * the console command that queues Purchasing's one-off copy of the stored items. The answer is a
 * list<string>.
 */
final readonly class ListOrganizationsWithPurchaseInvoiceItemsQuery implements QueryInterface
{
}
