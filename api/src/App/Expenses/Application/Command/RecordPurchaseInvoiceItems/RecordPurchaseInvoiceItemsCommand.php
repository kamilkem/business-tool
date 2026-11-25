<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\RecordPurchaseInvoiceItems;

use App\Expenses\Application\Command\PurchaseInvoiceItemPrimitives;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Shared\Application\Command\CommandInterface;

/**
 * Gives an imported invoice of the current organization that has no items the items its importer
 * read again from the stored document (#230, ADR 0040): invoices imported before items were kept.
 * An invoice that already has items stays as it is. Like ImportPurchaseInvoiceCommand it is for
 * importers only, has no actor and is never sent from Ui.
 *
 * @phpstan-import-type ItemPrimitives from PurchaseInvoiceItemPrimitives
 */
final readonly class RecordPurchaseInvoiceItemsCommand implements CommandInterface
{
    /** @param list<ItemPrimitives> $items in the document's order (PurchaseInvoiceItemPrimitives) */
    public function __construct(
        public string $organizationId,
        public string $invoiceId,
        public array $items,
    ) {
    }

    /** @param list<PurchaseInvoiceItemDetails> $items what a parser read from the document */
    public static function of(string $organizationId, string $invoiceId, array $items): self
    {
        return new self($organizationId, $invoiceId, PurchaseInvoiceItemPrimitives::of($items));
    }
}
