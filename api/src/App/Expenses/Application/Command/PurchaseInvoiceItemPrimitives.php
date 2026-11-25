<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command;

use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;

/**
 * The items of a purchase invoice as the primitives an importer's commands carry
 * (ImportPurchaseInvoiceCommand, RecordPurchaseInvoiceItemsCommand), and back. Quantities and prices
 * stay decimal strings (ADR 0040); the aggregate checks them.
 *
 * @phpstan-type ItemPrimitives array{position: int, name: string, sellerCode: ?string, gtin: ?string, unit: ?string, quantity: ?string, unitNetPrice: ?string, unitGrossPrice: ?string, discount: ?string, netAmount: ?int, grossAmount: ?int, vatAmount: ?int, vatRate: ?string, beforeCorrection: bool}
 */
final class PurchaseInvoiceItemPrimitives
{
    /**
     * @param list<PurchaseInvoiceItemDetails> $items
     *
     * @return list<ItemPrimitives>
     */
    public static function of(array $items): array
    {
        return array_map(static fn (PurchaseInvoiceItemDetails $item): array => [
            'position' => $item->position,
            'name' => $item->name,
            'sellerCode' => $item->sellerCode,
            'gtin' => $item->gtin,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'unitNetPrice' => $item->unitNetPrice,
            'unitGrossPrice' => $item->unitGrossPrice,
            'discount' => $item->discount,
            'netAmount' => $item->netAmount,
            'grossAmount' => $item->grossAmount,
            'vatAmount' => $item->vatAmount,
            'vatRate' => $item->vatRate,
            'beforeCorrection' => $item->beforeCorrection,
        ], $items);
    }

    /**
     * @param list<ItemPrimitives> $items
     *
     * @return list<PurchaseInvoiceItemDetails>
     */
    public static function toDetails(array $items): array
    {
        return array_map(static fn (array $item): PurchaseInvoiceItemDetails => new PurchaseInvoiceItemDetails(...$item), $items);
    }
}
