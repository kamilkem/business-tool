<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceCommand;
use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceHandler;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Organization\Domain\Model\Organization;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ImportPurchaseInvoiceHandler::class)]
#[CoversClass(ImportPurchaseInvoiceCommand::class)]
final class ImportPurchaseInvoiceHandlerTest extends TestCase
{
    private const string NOW = '2026-11-25 03:00:00';

    private InMemoryPurchaseInvoiceRepository $invoices;
    private InMemoryTenantContext $tenantContext;
    private Organization $organization;
    private ImportPurchaseInvoiceHandler $handler;

    protected function setUp(): void
    {
        $this->invoices = new InMemoryPurchaseInvoiceRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->organization = OrganizationMother::ownedBy(UserMother::any('olivia@example.com'));
        $this->tenantContext->switchTo($this->organization);
        $this->handler = new ImportPurchaseInvoiceHandler($this->invoices, $this->tenantContext, new MockClock(self::NOW));
    }

    public function test_it_imports_an_invoice_with_its_items_and_document(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $items = [
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica', sellerCode: 'MIE-0042', gtin: '5901234123457', unit: 'kg', quantity: '0.700', unitGrossPrice: '12.34567891', grossAmount: 864, vatAmount: 162, vatRate: '23'),
            new PurchaseInvoiceItemDetails(position: 2, name: 'Piwo', quantity: '4', unitNetPrice: '250.00', discount: '1.50', netAmount: 100_000, beforeCorrection: true),
        ];

        ($this->handler)(ImportPurchaseInvoiceCommand::of($this->organization->getId()->toRfc4122(), $id, 'ksef', 'K-1', self::details($items), '<Faktura/>', 'FA (3)'));

        $invoice = $this->invoices->findById(Uuid::fromString($id)) ?? self::fail('The invoice was not stored.');
        self::assertEquals([
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica', sellerCode: 'MIE-0042', gtin: '5901234123457', unit: 'kg', quantity: '0.7', unitGrossPrice: '12.34567891', grossAmount: 864, vatAmount: 162, vatRate: '23'),
            new PurchaseInvoiceItemDetails(position: 2, name: 'Piwo', quantity: '4', unitNetPrice: '250', discount: '1.5', netAmount: 100_000, beforeCorrection: true),
        ], $invoice->getItems());
        self::assertSame('FA (3)', $this->invoices->findByInvoiceId($invoice->getId())?->getFormat());
    }

    public function test_it_carries_the_items_as_primitives(): void
    {
        $command = ImportPurchaseInvoiceCommand::of('org', 'id', 'ksef', 'K-1', self::details([new PurchaseInvoiceItemDetails(position: 3, name: 'Chleb', unit: 'szt.', quantity: '1')]), '<Faktura/>', 'FA (3)');

        self::assertSame([[
            'position' => 3,
            'name' => 'Chleb',
            'sellerCode' => null,
            'gtin' => null,
            'unit' => 'szt.',
            'quantity' => '1',
            'unitNetPrice' => null,
            'unitGrossPrice' => null,
            'discount' => null,
            'netAmount' => null,
            'grossAmount' => null,
            'vatAmount' => null,
            'vatRate' => null,
            'beforeCorrection' => false,
        ]], $command->items);
    }

    public function test_it_stores_nothing_when_an_item_breaks_a_rule(): void
    {
        $id = Uuid::v7()->toRfc4122();

        try {
            ($this->handler)(ImportPurchaseInvoiceCommand::of($this->organization->getId()->toRfc4122(), $id, 'ksef', 'K-1', self::details([new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo', quantity: '0,7')]), '<Faktura/>', 'FA (3)'));
            self::fail('The invoice should have been rejected.');
        } catch (InvalidPurchaseInvoiceException $e) {
            self::assertStringContainsString('item quantity', $e->getMessage());
        }

        self::assertNull($this->invoices->findById(Uuid::fromString($id)));
    }

    public function test_it_refuses_an_invoice_imported_before(): void
    {
        $command = ImportPurchaseInvoiceCommand::of($this->organization->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), 'ksef', 'K-1', self::details([]), '<Faktura/>', 'FA (3)');
        ($this->handler)($command);

        $this->expectException(PurchaseInvoiceAlreadyImportedException::class);

        ($this->handler)(ImportPurchaseInvoiceCommand::of($this->organization->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), 'ksef', 'K-1', self::details([]), '<Faktura/>', 'FA (3)'));
    }

    /** @param list<PurchaseInvoiceItemDetails> $items */
    private static function details(array $items): PurchaseInvoiceDetails
    {
        return new PurchaseInvoiceDetails('FV/1/2026', '5265877635', 'Hurtownia', BusinessDate::fromString('2026-11-10'), PurchaseInvoiceKind::Regular, 'PLN', 20_000, 4_600, 24_600, [new PurchaseInvoiceVatAmount('23', 20_000, 4_600)], items: $items);
    }
}
