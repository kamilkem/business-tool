<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoice;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceDocumentRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetPurchaseInvoiceHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private PurchaseInvoiceDocumentRepositoryInterface $documents,
        private ExpenseCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws ExpensesManagementForbiddenException when the actor is an employee */
    public function __invoke(GetPurchaseInvoiceQuery $query): ?PurchaseInvoiceView
    {
        if (null === ExpensesActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $invoice = Uuid::isValid($query->invoiceId) ? $this->invoices->findById(Uuid::fromString($query->invoiceId)) : null;
        if (null === $invoice) {
            return null;
        }

        $categoryId = $invoice->getCategoryId();
        $category = null === $categoryId ? null : $this->categories->findById($categoryId);
        $formats = $this->documents->findFormatsByInvoiceIds([$invoice->getId()]);

        return PurchaseInvoiceView::of($invoice, $category?->getName(), $formats[$invoice->getId()->toRfc4122()] ?? null);
    }
}
