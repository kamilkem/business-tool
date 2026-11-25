<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoicesWithoutItems;

use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ListPurchaseInvoicesWithoutItemsHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<string> */
    public function __invoke(ListPurchaseInvoicesWithoutItemsQuery $query): array
    {
        $source = PurchaseInvoiceSource::tryFrom($query->source);
        if (null === $source || PurchaseInvoiceSource::Manual === $source
            || !$this->tenantContext->hasOrganization()
            || $this->tenantContext->getOrganization()->getId()->toRfc4122() !== $query->organizationId) {
            return [];
        }
        if (null !== $query->afterInvoiceId && !Uuid::isValid($query->afterInvoiceId)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an invoice id.', $query->afterInvoiceId));
        }

        $ids = $this->invoices->findImportedIdsWithDocumentAndNoItems(
            $source,
            null === $query->afterInvoiceId ? null : Uuid::fromString($query->afterInvoiceId),
            max(1, min($query->limit, ListPurchaseInvoicesWithoutItemsQuery::MAX_LIMIT)),
        );

        return array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);
    }
}
