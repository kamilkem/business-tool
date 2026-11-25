<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListOrganizationsWithPurchaseInvoiceItems;

use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListOrganizationsWithPurchaseInvoiceItemsHandler implements QueryHandlerInterface
{
    public function __construct(private PurchaseInvoiceRepositoryInterface $invoices)
    {
    }

    /** @return list<string> */
    public function __invoke(ListOrganizationsWithPurchaseInvoiceItemsQuery $query): array
    {
        return $this->invoices->findOrganizationIdsWithItems();
    }
}
