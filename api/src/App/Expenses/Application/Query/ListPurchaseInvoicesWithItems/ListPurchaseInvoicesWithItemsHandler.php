<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoicesWithItems;

use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListPurchaseInvoicesWithItemsHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<PurchaseInvoiceWithItemsView> */
    public function __invoke(ListPurchaseInvoicesWithItemsQuery $query): array
    {
        if (!$this->tenantContext->hasOrganization()
            || $this->tenantContext->getOrganization()->getId()->toRfc4122() !== $query->organizationId) {
            return [];
        }
        if (null !== $query->afterInvoiceId && !Uuid::isValid($query->afterInvoiceId)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an invoice id.', $query->afterInvoiceId));
        }

        $invoices = $this->invoices->findWithItemsAfter(
            null === $query->afterInvoiceId ? null : Uuid::fromString($query->afterInvoiceId),
            max(1, min($query->limit, ListPurchaseInvoicesWithItemsQuery::MAX_LIMIT)),
        );

        return array_map(static fn ($invoice): PurchaseInvoiceWithItemsView => PurchaseInvoiceWithItemsView::fromInvoice($query->organizationId, $invoice), $invoices);
    }
}
