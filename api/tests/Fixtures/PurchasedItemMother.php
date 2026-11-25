<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Expenses\Domain\Event\PurchaseInvoiceItemsRecorded;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsCommand;
use Symfony\Component\Uid\Uuid;

/**
 * The items of an invoice as Expenses publishes them (PurchaseInvoiceItemsRecorded) and the command
 * Purchasing records them with.
 *
 * @phpstan-import-type RecordedItem from PurchaseInvoiceItemsRecorded
 */
final class PurchasedItemMother
{
    /**
     * One item as published, with every field set unless overridden.
     *
     * @param array<string, mixed> $overrides
     *
     * @return RecordedItem
     */
    public static function recorded(int $index, array $overrides = []): array
    {
        /** @var RecordedItem */
        return $overrides + [
            'index' => $index,
            'position' => $index,
            'name' => 'Jack Daniel\'s 0,7l',
            'sellerCode' => 'JD-07',
            'gtin' => '5099873089798',
            'unit' => 'szt.',
            'quantity' => '6',
            'unitNetPrice' => '81.30081301',
            'unitGrossPrice' => '100',
            'discount' => '1.5',
            'netAmount' => 48_780,
            'grossAmount' => 60_000,
            'vatAmount' => 11_220,
            'vatRate' => '23',
            'beforeCorrection' => false,
        ];
    }

    /**
     * @param list<RecordedItem>|null $items two by default
     */
    public static function command(
        string $organizationId,
        ?string $invoiceId = null,
        ?array $items = null,
        string $issueDate = '2026-11-10',
        string $kind = 'regular',
        string $currency = 'PLN',
        ?string $sellerTaxId = '5265877635',
        string $sellerName = 'Hurtownia Napojów',
    ): RecordPurchasedItemsCommand {
        return RecordPurchasedItemsCommand::of(
            $organizationId,
            $invoiceId ?? Uuid::v7()->toRfc4122(),
            $issueDate,
            $kind,
            $currency,
            $sellerTaxId,
            $sellerName,
            $items ?? [self::recorded(1), self::recorded(2, ['name' => 'Cola 1l', 'quantity' => '12', 'unit' => 'l'])],
        );
    }
}
