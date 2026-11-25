<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Application\Command\PurchaseInvoiceItemPrimitives;
use App\Expenses\Application\Command\RecordPurchaseInvoiceItems\RecordPurchaseInvoiceItemsCommand;
use App\Expenses\Application\Command\RecordPurchaseInvoiceItems\RecordPurchaseInvoiceItemsHandler;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordPurchaseInvoiceItemsHandler::class)]
#[CoversClass(RecordPurchaseInvoiceItemsCommand::class)]
#[CoversClass(PurchaseInvoiceItemPrimitives::class)]
#[CoversClass(PurchaseInvoice::class)]
final class RecordPurchaseInvoiceItemsHandlerTest extends TestCase
{
    private InMemoryPurchaseInvoiceRepository $invoices;
    private InMemoryTenantContext $tenantContext;
    private Organization $organization;
    private RecordPurchaseInvoiceItemsHandler $handler;

    protected function setUp(): void
    {
        $this->invoices = new InMemoryPurchaseInvoiceRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'));
        $this->tenantContext->switchTo($this->organization);
        $this->handler = new RecordPurchaseInvoiceItemsHandler($this->invoices, $this->tenantContext);
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

    public function test_it_sets_the_items_only_once(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported());
        $this->record($invoice, [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]);

        $this->record($invoice, [new PurchaseInvoiceItemDetails(position: 1, name: 'Wino'), new PurchaseInvoiceItemDetails(position: 2, name: 'Woda')]);

        self::assertEquals([new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')], $invoice->getItems());
        self::assertSame(1, $this->invoices->saveCallCount, 'Nothing changed, so nothing is saved.');
    }

    public function test_it_leaves_alone_an_invoice_imported_with_its_items(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported(items: [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]));

        $this->record($invoice, [new PurchaseInvoiceItemDetails(position: 1, name: 'Wino')]);

        self::assertEquals([new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')], $invoice->getItems());
        self::assertSame(0, $this->invoices->saveCallCount);
    }

    public function test_it_changes_nothing_without_items(): void
    {
        $invoice = $this->store(PurchaseInvoiceMother::imported());

        $this->record($invoice, []);

        self::assertSame([], $invoice->getItems());
        self::assertSame(0, $this->invoices->saveCallCount);
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
