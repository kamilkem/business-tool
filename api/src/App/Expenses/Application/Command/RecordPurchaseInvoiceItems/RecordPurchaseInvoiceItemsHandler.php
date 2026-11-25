<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\RecordPurchaseInvoiceItems;

use App\Expenses\Application\Command\PurchaseInvoiceItemPrimitives;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RecordPurchaseInvoiceItemsHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * @throws PurchaseInvoiceNotFoundException when the organization has no such invoice
     * @throws InvalidPurchaseInvoiceException  when the invoice was entered by hand or an item breaks a rule
     */
    public function __invoke(RecordPurchaseInvoiceItemsCommand $command): void
    {
        $organization = $this->tenantContext->getOrganization();
        if ($organization->getId()->toRfc4122() !== $command->organizationId) {
            throw new \LogicException('Items are recorded only in the organization the importer runs in.');
        }

        $invoice = (Uuid::isValid($command->invoiceId) ? $this->invoices->findById(Uuid::fromString($command->invoiceId)) : null)
            ?? throw PurchaseInvoiceNotFoundException::withId($command->invoiceId);

        if ($invoice->recordItems(PurchaseInvoiceItemPrimitives::toDetails($command->items), $organization->getCountryCode())) {
            $this->invoices->save($invoice);
        }
    }
}
