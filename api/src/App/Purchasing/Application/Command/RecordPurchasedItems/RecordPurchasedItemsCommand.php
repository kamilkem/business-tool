<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\RecordPurchasedItems;

use App\Expenses\Domain\Event\PurchaseInvoiceItemsRecorded;
use App\Shared\Application\Command\CommandInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Records the items of one purchase invoice in the current organization (ADR 0041 §2): an upsert
 * keyed by the invoice's id and each item's index. An item Purchasing already has stays as it is,
 * whatever it was linked to since; the others are added as `unassigned`. It is sent by the event
 * handler of PurchaseInvoiceItemsRecorded and by the job that copies the items stored before, as the
 * importer that brought them: it has no actor and is never sent from Ui (ADR 0033 §9). The
 * invoice's items are its unit: they are checked and stored together.
 *
 * @phpstan-import-type RecordedItem from PurchaseInvoiceItemsRecorded
 *
 * @phpstan-type ItemPrimitives array{id: string, index: int, position: int, name: string, sellerCode: ?string, gtin: ?string, unit: ?string, quantity: ?string, unitNetPrice: ?string, unitGrossPrice: ?string, discount: ?string, netAmount: ?int, grossAmount: ?int, vatAmount: ?int, vatRate: ?string, beforeCorrection: bool}
 */
final readonly class RecordPurchasedItemsCommand implements CommandInterface
{
    /**
     * @param string               $issueDate Y-m-d
     * @param string               $currency  ISO 4217
     * @param list<ItemPrimitives> $items     each with the id it gets if it is new (UUID v7, ADR 0009)
     */
    public function __construct(
        public string $organizationId,
        public string $invoiceId,
        public string $issueDate,
        public string $invoiceKind,
        public string $currency,
        public ?string $sellerTaxId,
        public string $sellerName,
        public array $items,
    ) {
    }

    /**
     * The command for an invoice's items as Expenses gives them; the new rows get fresh ids.
     *
     * @param list<RecordedItem> $items
     */
    public static function of(
        string $organizationId,
        string $invoiceId,
        string $issueDate,
        string $invoiceKind,
        string $currency,
        ?string $sellerTaxId,
        string $sellerName,
        array $items,
    ): self {
        return new self(
            $organizationId,
            $invoiceId,
            $issueDate,
            $invoiceKind,
            $currency,
            $sellerTaxId,
            $sellerName,
            array_map(static fn (array $item): array => ['id' => Uuid::v7()->toRfc4122()] + $item, $items),
        );
    }
}
