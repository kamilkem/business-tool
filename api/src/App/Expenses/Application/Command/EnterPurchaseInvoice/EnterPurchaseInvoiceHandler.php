<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\EnterPurchaseInvoice;

use App\Expenses\Application\Command\PurchaseInvoiceDetailsInput;
use App\Expenses\Application\ExpensesActor;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class EnterPurchaseInvoiceHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException        when the actor is not an active member of the organization
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPurchaseInvoiceException      when the data breaks a rule of the invoice
     * @throws InvalidBusinessDateException         when the issue date is not YYYY-MM-DD
     */
    public function __invoke(EnterPurchaseInvoiceCommand $command): void
    {
        $actor = ExpensesActor::resolve($this->queryBus, $command->organizationId, $command->actorId);
        $organization = $actor->organization($this->queryBus, $command->organizationId);

        $details = PurchaseInvoiceDetailsInput::toDetails(
            $command->invoiceNumber,
            $command->sellerTaxId,
            $command->sellerName,
            $command->issueDate,
            $command->kind,
            $command->currency ?? $organization->currency,
            $command->netTotal,
            $command->vatTotal,
            $command->grossTotal,
            $command->vatLines,
        );
        $invoice = PurchaseInvoice::enter(
            Uuid::fromString($command->invoiceId),
            $details,
            $organization->countryCode,
            $organization->currency,
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->invoices->add($invoice);
    }
}
