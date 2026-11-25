<?php

declare(strict_types=1);

namespace App\Purchasing\Application\EventHandler;

use App\Expenses\Domain\Event\PurchaseInvoiceItemsRecorded;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;

/**
 * An invoice that got its items is copied into Purchasing as purchased items (ADR 0041 §2, ADR 0033
 * §9), as the importer that brought them. It runs synchronously in the job that imported the invoice or
 * read its items, with the organization selected.
 */
final readonly class RecordPurchasedItemsOnPurchaseInvoiceItemsRecorded implements EventHandlerInterface
{
    public function __construct(private CommandBusInterface $commandBus)
    {
    }

    public function __invoke(PurchaseInvoiceItemsRecorded $event): void
    {
        $this->commandBus->handle(RecordPurchasedItemsCommand::of(
            $event->organizationId,
            $event->invoiceId,
            $event->issueDate,
            $event->kind,
            $event->currency,
            $event->sellerTaxId,
            $event->sellerName,
            $event->items,
        ));
    }
}
