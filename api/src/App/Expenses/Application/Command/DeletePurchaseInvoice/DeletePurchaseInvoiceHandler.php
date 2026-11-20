<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\DeletePurchaseInvoice;

use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Expenses\Domain\Exception\PurchaseInvoiceReadOnlyException;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeletePurchaseInvoiceHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException        when the actor is not an active member of the organization
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws PurchaseInvoiceNotFoundException     when the invoice is not one of the organization's
     * @throws PurchaseInvoiceReadOnlyException     when the invoice is imported
     */
    public function __invoke(DeletePurchaseInvoiceCommand $command): void
    {
        $actor = ExpensesActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $invoice = Uuid::isValid($command->invoiceId) ? $this->invoices->findById(Uuid::fromString($command->invoiceId)) : null;
        $invoice ??= throw PurchaseInvoiceNotFoundException::withId($command->invoiceId);

        $invoice->assertCanBeDeleted($actor->id, $actor->role);

        $this->invoices->remove($invoice);
    }
}
