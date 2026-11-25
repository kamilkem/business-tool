<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Application\Command\PurchaseInvoiceItemPrimitives;
use App\Expenses\Application\Command\RecordPurchaseInvoiceItems\RecordPurchaseInvoiceItemsCommand;
use App\Expenses\Application\Command\RecordPurchaseInvoiceItems\RecordPurchaseInvoiceItemsHandler;
use App\Expenses\Domain\Event\PurchaseInvoiceItemsRecorded;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordPurchaseInvoiceItemsHandler::class)]
#[CoversClass(RecordPurchaseInvoiceItemsCommand::class)]
#[CoversClass(PurchaseInvoiceItemPrimitives::class)]
#[CoversClass(PurchaseInvoice::class)]
#[CoversClass(PurchaseInvoiceItemsRecorded::class)]
final class RecordPurchaseInvoiceItemsHandlerTest extends TestCase
{
    private InMemoryPurchaseInvoiceRepository $invoices;
    private InMemoryTenantContext $tenantContext;
    private RecordingEventPublisher $events;
    private Organization $organization;
    private RecordPurchaseInvoiceItemsHandler $handler;

    protected function setUp(): void
    {
        $this->invoices = new InMemoryPurchaseInvoiceRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'));
        $this->tenantContext->switchTo($this->organization);
        $this->events = new RecordingEventPublisher();
        $this->handler = new RecordPurchaseInvoiceItemsHandler($this->invoices, $this->tenantContext, $this->events);
    }

    public function test_it_sets_the_items_of_an_imported_invoice_without_items(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported());

        $this->record($invoice, [
            new PurchaseInvoiceItemDetails(position: 1, name: ' Polędwica ', unit: 'kg', quantity: '0.700', unitNetPrice: '45.00', netAmount: 3_150, vatRate: '23'),
            new PurchaseInvoiceItemDetails(position: 2, name: 'Piwo', quantity: '4', discount: '1.50'),
        ]);

        self::assertEquals([
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica', unit: 'kg', quantity: '0.7', unitNetPrice: '45', netAmount: 3_150, vatRate: '23'),
            new PurchaseInvoiceItemDetails(position: 2, name: 'Piwo', quantity: '4', discount: '1.5'),
        ], $invoice->getItems());
        self::assertSame(1, $this->invoices->saveCallCount);
    }

    public function test_it_publishes_the_items_it_stored_with_their_index(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported(issueDate: '2026-11-09', sellerName: 'Hurtownia', sellerTaxId: '5265877635', currency: 'EUR', vatLines: [new PurchaseInvoiceVatAmount('23', 20_000, 4_600, 20_000)], kind: PurchaseInvoiceKind::Correction));

        $this->record($invoice, [
            new PurchaseInvoiceItemDetails(position: 7, name: ' Polędwica ', unit: 'kg', quantity: '0.700', unitNetPrice: '45.00', netAmount: 3_150, vatRate: '23'),
            new PurchaseInvoiceItemDetails(position: 7, name: 'Piwo', quantity: '4', beforeCorrection: true),
        ]);

        self::assertCount(1, $this->events->events);
        $event = $this->events->events[0];
        self::assertInstanceOf(PurchaseInvoiceItemsRecorded::class, $event);
        self::assertSame($this->organization->getId()->toRfc4122(), $event->organizationId);
        self::assertSame($invoice->getId()->toRfc4122(), $event->invoiceId);
        self::assertSame('2026-11-09', $event->issueDate);
        self::assertSame('correction', $event->kind);
        self::assertSame('EUR', $event->currency);
        self::assertSame('5265877635', $event->sellerTaxId);
        self::assertSame('Hurtownia', $event->sellerName);
        self::assertSame([
            ['index' => 1, 'position' => 7, 'name' => 'Polędwica', 'sellerCode' => null, 'gtin' => null, 'unit' => 'kg', 'quantity' => '0.7', 'unitNetPrice' => '45', 'unitGrossPrice' => null, 'discount' => null, 'netAmount' => 3_150, 'grossAmount' => null, 'vatAmount' => null, 'vatRate' => '23', 'beforeCorrection' => false],
            ['index' => 2, 'position' => 7, 'name' => 'Piwo', 'sellerCode' => null, 'gtin' => null, 'unit' => null, 'quantity' => '4', 'unitNetPrice' => null, 'unitGrossPrice' => null, 'discount' => null, 'netAmount' => null, 'grossAmount' => null, 'vatAmount' => null, 'vatRate' => null, 'beforeCorrection' => true],
        ], $event->items);
    }

    public function test_it_sets_the_items_only_once(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported());
        $this->record($invoice, [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]);

        $this->record($invoice, [new PurchaseInvoiceItemDetails(position: 1, name: 'Wino'), new PurchaseInvoiceItemDetails(position: 2, name: 'Woda')]);

        self::assertEquals([new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')], $invoice->getItems());
        self::assertSame(1, $this->invoices->saveCallCount, 'Nothing changed, so nothing is saved.');
        self::assertCount(1, $this->events->events, 'Nothing changed, so nothing is published.');
    }

    public function test_it_leaves_alone_an_invoice_imported_with_its_items(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported(items: [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]));

        $this->record($invoice, [new PurchaseInvoiceItemDetails(position: 1, name: 'Wino')]);

        self::assertEquals([new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')], $invoice->getItems());
        self::assertSame(0, $this->invoices->saveCallCount);
        self::assertSame([], $this->events->events);
    }

    public function test_it_changes_nothing_without_items(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported());

        $this->record($invoice, []);

        self::assertSame([], $invoice->getItems());
        self::assertSame(0, $this->invoices->saveCallCount);
        self::assertSame([], $this->events->events);
    }

    public function test_it_refuses_a_manual_invoice(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::manual());

        try {
            $this->record($invoice, [new PurchaseInvoiceItemDetails(position: 1, name: 'Ryba')]);
            self::fail('A manual invoice has no items.');
        } catch (InvalidPurchaseInvoiceException $e) {
            self::assertSame('expenses.invalid_purchase_invoice.items_on_manual', $e->errorCode());
        }

        self::assertSame([], $invoice->getItems());
        self::assertSame(0, $this->invoices->saveCallCount);
        self::assertSame([], $this->events->events);
    }

    public function test_it_sets_no_item_when_one_breaks_a_rule(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported());

        try {
            $this->record($invoice, [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo'), new PurchaseInvoiceItemDetails(position: 2, name: 'Wino', quantity: '0,7')]);
            self::fail('The quantity is not a decimal string.');
        } catch (InvalidPurchaseInvoiceException $e) {
            self::assertStringContainsString('item quantity', $e->getMessage());
        }

        self::assertSame([], $invoice->getItems());
    }

    public function test_it_reports_an_unknown_invoice_as_not_found(): void
    {
        $this->expectException(PurchaseInvoiceNotFoundException::class);

        ($this->handler)(new RecordPurchaseInvoiceItemsCommand($this->organization->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), []));
    }

    public function test_it_refuses_another_organization_than_the_one_the_importer_runs_in(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported());

        $this->expectException(\LogicException::class);

        ($this->handler)(RecordPurchaseInvoiceItemsCommand::of(Uuid::v7()->toRfc4122(), $invoice->getId()->toRfc4122(), [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]));
    }

    private function store(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $this->invoices->add($invoice);

        return $invoice;
    }

    /** @param list<PurchaseInvoiceItemDetails> $items */
    private function record(PurchaseInvoice $invoice, array $items): void
    {
        ($this->handler)(RecordPurchaseInvoiceItemsCommand::of($this->organization->getId()->toRfc4122(), $invoice->getId()->toRfc4122(), $items));
    }
}
