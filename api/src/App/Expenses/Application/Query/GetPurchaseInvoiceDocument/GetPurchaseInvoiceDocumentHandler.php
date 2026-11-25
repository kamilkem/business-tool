<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoiceDocument;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Repository\PurchaseInvoiceDocumentRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetPurchaseInvoiceDocumentHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceDocumentRepositoryInterface $documents,
        private QueryBusInterface $queryBus,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @throws ExpensesManagementForbiddenException when the actor is an employee */
    public function __invoke(GetPurchaseInvoiceDocumentQuery $query): ?PurchaseInvoiceDocumentView
    {
        if (null === $query->actorId) {
            // An importer: only inside the organization its job runs in.
            if (!$this->tenantContext->hasOrganization() || $this->tenantContext->getOrganization()->getId()->toRfc4122() !== $query->organizationId) {
                return null;
            }
        } elseif (null === ExpensesActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $document = Uuid::isValid($query->invoiceId) ? $this->documents->findByInvoiceId(Uuid::fromString($query->invoiceId)) : null;

        return null === $document ? null : PurchaseInvoiceDocumentView::fromDocument($document);
    }
}
