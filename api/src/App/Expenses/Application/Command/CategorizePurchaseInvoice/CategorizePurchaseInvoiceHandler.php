<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\CategorizePurchaseInvoice;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpenseCategoryArchivedException;
use App\Expenses\Domain\Exception\ExpenseCategoryNotFoundException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CategorizePurchaseInvoiceHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private ExpenseCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException        when the actor is not an active member of the organization
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws PurchaseInvoiceNotFoundException     when the invoice is not one of the organization's
     * @throws ExpenseCategoryNotFoundException     when the category is not one of the organization's
     * @throws ExpenseCategoryArchivedException     when the category is archived and not the invoice's already
     */
    public function __invoke(CategorizePurchaseInvoiceCommand $command): void
    {
        $actor = ExpensesActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $invoice = Uuid::isValid($command->invoiceId) ? $this->invoices->findById(Uuid::fromString($command->invoiceId)) : null;
        $invoice ??= throw PurchaseInvoiceNotFoundException::withId($command->invoiceId);

        $category = null;
        if (null !== $command->categoryId) {
            $category = Uuid::isValid($command->categoryId) ? $this->categories->findById(Uuid::fromString($command->categoryId)) : null;
            $category ??= throw ExpenseCategoryNotFoundException::withId($command->categoryId);
        }

        $invoice->categorize($category, $actor->id, $actor->role);

        $this->invoices->save($invoice);
    }
}
