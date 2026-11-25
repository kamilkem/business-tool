<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsCommand;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsHandler;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchasedItemMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(RecordPurchasedItemsHandler::class)]
#[CoversClass(RecordPurchasedItemsCommand::class)]
#[CoversClass(PurchasedItem::class)]
final class RecordPurchasedItemsHandlerTest extends TestCase
{
    private const string NOW = '2026-11-25 03:00:00';

    private InMemoryPurchasedItemRepository $items;
    private Organization $organization;
    private RecordPurchasedItemsHandler $handler;

    protected function setUp(): void
    {
        $this->items = new InMemoryPurchasedItemRepository();
        $tenantContext = new InMemoryTenantContext();
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'));
        $tenantContext->switchTo($this->organization);
        $this->handler = new RecordPurchasedItemsHandler($this->items, $tenantContext, new MockClock(self::NOW));
    }

    public function test_it_copies_the_invoice_and_each_item_as_unassigned(): void
    {
        $invoiceId = Uuid::v7()->toRfc4122();

        $this->record(PurchasedItemMother::command($this->organizationId(), $invoiceId, [
            PurchasedItemMother::recorded(1),
            PurchasedItemMother::recorded(2, ['position' => 7, 'name' => 'Cola 1l', 'sellerCode' => null, 'gtin' => null, 'unit' => null, 'quantity' => '-2', 'unitNetPrice' => null, 'unitGrossPrice' => null, 'discount' => null, 'netAmount' => -300, 'grossAmount' => null, 'vatAmount' => null, 'vatRate' => null, 'beforeCorrection' => true]),
        ], issueDate: '2026-11-09', kind: 'correction', currency: 'EUR', sellerTaxId: null, sellerName: 'Foreign Seller'));

        $stored = $this->items->findByInvoice(Uuid::fromString($invoiceId));
        self::assertCount(2, $stored);

        [$first, $second] = $stored;
        self::assertSame($invoiceId, $first->getInvoiceId()->toRfc4122());
        self::assertSame(1, $first->getItemIndex());
        self::assertTrue(BusinessDate::fromString('2026-11-09')->equals($first->getInvoiceIssueDate()));
        self::assertSame('correction', $first->getInvoiceKind());
        self::assertSame('EUR', $first->getCurrency());
        self::assertNull($first->getSellerTaxId());
        self::assertSame('Foreign Seller', $first->getSellerName());
        self::assertSame(1, $first->getPosition());
        self::assertSame('Jack Daniel\'s 0,7l', $first->getName());
        self::assertSame('JD-07', $first->getSellerCode());
        self::assertSame('5099873089798', $first->getGtin());
        self::assertSame('szt.', $first->getUnit());
        self::assertSame('6', $first->getQuantity());
        self::assertSame('81.30081301', $first->getUnitNetPrice());
        self::assertSame('100', $first->getUnitGrossPrice());
        self::assertSame('1.5', $first->getDiscount());
        self::assertSame(48_780, $first->getNetAmount());
        self::assertSame(60_000, $first->getGrossAmount());
        self::assertSame(11_220, $first->getVatAmount());
        self::assertSame('23', $first->getVatRate());
        self::assertFalse($first->isBeforeCorrection());
        self::assertSame(self::NOW, $first->getCreatedAt()->format('Y-m-d H:i:s'));

        self::assertSame(2, $second->getItemIndex());
        self::assertSame(7, $second->getPosition(), 'The printed position is kept; the index is the key.');
        self::assertSame('-2', $second->getQuantity());
        self::assertSame(-300, $second->getNetAmount());
        self::assertNull($second->getGrossAmount());
        self::assertTrue($second->isBeforeCorrection());

        foreach ($stored as $item) {
            self::assertNull($item->getProductId());
            self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
            self::assertNull($item->getPackageSize());
        }
    }

    public function test_it_is_idempotent_and_leaves_the_copied_fields_as_they_are(): void
    {
        $invoiceId = Uuid::v7()->toRfc4122();
        $this->record(PurchasedItemMother::command($this->organizationId(), $invoiceId));
        $before = $this->items->findByInvoice(Uuid::fromString($invoiceId));
        self::assertSame(1, $this->items->addAllCallCount);

        // The same invoice again, even with different values: what was copied never changes.
        $this->record(PurchasedItemMother::command($this->organizationId(), $invoiceId, [
            PurchasedItemMother::recorded(1, ['name' => 'Something else', 'quantity' => '99', 'netAmount' => 1]),
            PurchasedItemMother::recorded(2, ['name' => 'Another']),
        ], issueDate: '2026-01-01', currency: 'EUR'));

        $after = $this->items->findByInvoice(Uuid::fromString($invoiceId));
        self::assertSame($before, $after, 'The same objects: nothing was replaced.');
        self::assertSame('Jack Daniel\'s 0,7l', $after[0]->getName());
        self::assertSame('6', $after[0]->getQuantity());
        self::assertSame('PLN', $after[0]->getCurrency());
        self::assertTrue(BusinessDate::fromString('2026-11-10')->equals($after[0]->getInvoiceIssueDate()));
        self::assertCount(2, $this->items->all());
    }

    public function test_it_adds_only_the_items_it_does_not_have(): void
    {
        $invoiceId = Uuid::v7()->toRfc4122();
        $this->record(PurchasedItemMother::command($this->organizationId(), $invoiceId, [PurchasedItemMother::recorded(1)]));
        $first = $this->items->findByInvoice(Uuid::fromString($invoiceId))[0];

        $this->record(PurchasedItemMother::command($this->organizationId(), $invoiceId, [PurchasedItemMother::recorded(1), PurchasedItemMother::recorded(2), PurchasedItemMother::recorded(3)]));

        $stored = $this->items->findByInvoice(Uuid::fromString($invoiceId));
        self::assertSame([1, 2, 3], array_map(static fn (PurchasedItem $item): int => $item->getItemIndex(), $stored));
        self::assertSame($first, $stored[0]);
    }

    public function test_it_keeps_the_items_of_different_invoices_apart(): void
    {
        $this->record(PurchasedItemMother::command($this->organizationId(), null, [PurchasedItemMother::recorded(1)]));
        $this->record(PurchasedItemMother::command($this->organizationId(), null, [PurchasedItemMother::recorded(1)]));

        self::assertCount(2, $this->items->all());
    }

    public function test_it_stores_repeated_printed_positions(): void
    {
        $invoiceId = Uuid::v7()->toRfc4122();

        $this->record(PurchasedItemMother::command($this->organizationId(), $invoiceId, [
            PurchasedItemMother::recorded(1, ['position' => 1]),
            PurchasedItemMother::recorded(2, ['position' => 1]),
        ]));

        self::assertCount(2, $this->items->findByInvoice(Uuid::fromString($invoiceId)));
    }

    public function test_it_records_nothing_without_items(): void
    {
        $this->record(PurchasedItemMother::command($this->organizationId(), null, []));

        self::assertSame([], $this->items->all());
    }

    public function test_it_refuses_an_index_given_twice_and_stores_none_of_the_items(): void
    {
        try {
            $this->record(PurchasedItemMother::command($this->organizationId(), null, [PurchasedItemMother::recorded(1), PurchasedItemMother::recorded(1)]));
            self::fail('An index names one item.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Item 1', $e->getMessage());
        }

        self::assertSame([], $this->items->all());
    }

    public function test_it_refuses_an_index_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->record(PurchasedItemMother::command($this->organizationId(), null, [PurchasedItemMother::recorded(0)]));
    }

    public function test_it_refuses_another_organization_than_the_one_the_writer_runs_in(): void
    {
        $this->expectException(\LogicException::class);

        $this->record(PurchasedItemMother::command(Uuid::v7()->toRfc4122()));
    }

    public function test_the_command_gives_each_item_its_own_uuid_v7(): void
    {
        $command = PurchasedItemMother::command($this->organizationId());

        $ids = array_column($command->items, 'id');
        self::assertCount(2, array_unique($ids));
        foreach ($ids as $id) {
            self::assertInstanceOf(UuidV7::class, Uuid::fromString($id));
        }
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    private function record(RecordPurchasedItemsCommand $command): void
    {
        ($this->handler)($command);
    }
}
