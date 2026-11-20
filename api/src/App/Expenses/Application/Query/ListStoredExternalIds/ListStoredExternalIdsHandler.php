<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListStoredExternalIds;

use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListStoredExternalIdsHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<string> */
    public function __invoke(ListStoredExternalIdsQuery $query): array
    {
        $source = PurchaseInvoiceSource::tryFrom($query->source);
        if (null === $source || PurchaseInvoiceSource::Manual === $source || [] === $query->externalIds
            || !$this->tenantContext->hasOrganization()
            || $this->tenantContext->getOrganization()->getId()->toRfc4122() !== $query->organizationId) {
            return [];
        }

        return $this->invoices->findStoredExternalIds($source, $query->externalIds);
    }
}
