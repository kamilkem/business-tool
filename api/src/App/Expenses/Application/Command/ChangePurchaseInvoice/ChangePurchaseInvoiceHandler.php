<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\ChangePurchaseInvoice;

use App\Expenses\Application\Command\PurchaseInvoiceDetailsInput;
use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Expenses\Domain\Exception\PurchaseInvoiceReadOnlyException;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Symfony\Component\Uid\Uuid;

final readonly class ChangePurchaseInvoiceHandler implements CommandHandlerInterface
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
     * @throws PurchaseInvoiceReadOnlyException     when the invoice is imported and the details differ from its own
     * @throws InvalidPurchaseInvoiceException      when the data breaks a rule of the invoice
     * @throws InvalidBusinessDateException         when the issue date is not YYYY-MM-DD
     */
    public function __invoke(ChangePurchaseInvoiceCommand $command): void
    {
        $actor = ExpensesActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $invoice = Uuid::isValid($command->invoiceId) ? $this->invoices->findById(Uuid::fromString($command->invoiceId)) : null;
        $invoice ??= throw PurchaseInvoiceNotFoundException::withId($command->invoiceId);

        $details = PurchaseInvoiceDetailsInput::toDetails(
            $command->invoiceNumber,
            $command->sellerTaxId,
            $command->sellerName,
            $command->issueDate,
            $command->kind,
            $command->currency,
            $command->netTotal,
            $command->vatTotal,
            $command->grossTotal,
            $command->vatLines,
        );
        $invoice->change($details, $actor->organization($this->queryBus, $command->organizationId)->countryCode, $actor->id, $actor->role);

        $this->invoices->save($invoice);
    }
}
