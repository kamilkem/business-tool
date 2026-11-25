<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Expenses\Domain\Event\PurchaseInvoiceItemsRecorded;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsCommand;
use App\Purchasing\Application\EventHandler\RecordPurchasedItemsOnPurchaseInvoiceItemsRecorded;
use App\Tests\Fixtures\PurchasedItemMother;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordPurchasedItemsOnPurchaseInvoiceItemsRecorded::class)]
#[CoversClass(RecordPurchasedItemsCommand::class)]
final class RecordPurchasedItemsOnPurchaseInvoiceItemsRecordedTest extends TestCase
{
    public function test_it_sends_the_invoice_and_its_items_as_one_command(): void
    {
        $organizationId = Uuid::v7()->toRfc4122();
        $invoiceId = Uuid::v7()->toRfc4122();
        $items = [PurchasedItemMother::recorded(1), PurchasedItemMother::recorded(2, ['name' => 'Cola 1l'])];
        $commandBus = new RecordingCommandBus();

        (new RecordPurchasedItemsOnPurchaseInvoiceItemsRecorded($commandBus))(new PurchaseInvoiceItemsRecorded(
            $organizationId,
            $invoiceId,
            '2026-11-10',
            'correction',
            'EUR',
            null,
            'Foreign Seller',
            $items,
        ));

        $commands = $commandBus->commandsOf(RecordPurchasedItemsCommand::class);
        self::assertCount(1, $commands);
        $command = $commands[0];
        self::assertSame($organizationId, $command->organizationId);
        self::assertSame($invoiceId, $command->invoiceId);
        self::assertSame('2026-11-10', $command->issueDate);
        self::assertSame('correction', $command->invoiceKind);
        self::assertSame('EUR', $command->currency);
        self::assertNull($command->sellerTaxId);
        self::assertSame('Foreign Seller', $command->sellerName);
        self::assertSame($items, array_map(static fn (array $item): array => array_diff_key($item, ['id' => true]), $command->items));
        foreach ($command->items as $item) {
            self::assertTrue(Uuid::isValid($item['id']));
        }
    }
}
