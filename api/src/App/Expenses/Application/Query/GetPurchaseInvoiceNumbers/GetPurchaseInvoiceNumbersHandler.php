<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoiceNumbers;

use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetPurchaseInvoiceNumbersHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return array<string, string> the number by invoice id */
    public function __invoke(GetPurchaseInvoiceNumbersQuery $query): array
    {
        if (!$this->tenantContext->hasOrganization()
            || $this->tenantContext->getOrganization()->getId()->toRfc4122() !== $query->organizationId) {
            return [];
        }
        if (\count($query->invoiceIds) > GetPurchaseInvoiceNumbersQuery::MAX_INVOICES) {
            throw new \InvalidArgumentException(\sprintf('At most %d invoice numbers are asked for at once.', GetPurchaseInvoiceNumbersQuery::MAX_INVOICES));
        }

        $ids = [];
        foreach ($query->invoiceIds as $invoiceId) {
            if (Uuid::isValid($invoiceId)) {
                $ids[$invoiceId] = Uuid::fromString($invoiceId);
            }
        }

        return $this->invoices->findInvoiceNumbers(array_values($ids));
    }
}
