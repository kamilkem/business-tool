<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceCommand;
use App\Expenses\Application\Command\RecordPurchaseInvoiceItems\RecordPurchaseInvoiceItemsCommand;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Factory\OrganizationFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The whole path of ADR 0041 §2 in the container, through the real buses and repositories:
 * Expenses publishes PurchaseInvoiceItemsRecorded when an invoice is imported with items or gets
 * them later, and Purchasing's event handler records the purchased items.
 */
final class RecordPurchasedItemsFromExpensesTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private TenantContextInterface $tenantContext;
    private CommandBusInterface $commandBus;
    private PurchasedItemRepositoryInterface $items;

    protected function setUp(): void
    {
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->commandBus = self::getContainer()->get(CommandBusInterface::class);
        $this->items = self::getContainer()->get(PurchasedItemRepositoryInterface::class);
    }

    public function test_importing_an_invoice_with_items_records_them_as_unassigned_purchased_items(): void
    {
        $organization = $this->switchToNewOrganization();
        $invoiceId = Uuid::v7()->toRfc4122();

        $this->import($organization, $invoiceId, 'K-1', [
            new PurchaseInvoiceItemDetails(position: 1, name: 'Jack Daniel\'s 0,7l', unit: 'szt.', quantity: '6', unitNetPrice: '81.30081301', netAmount: 48_780, vatRate: '23'),
            new PurchaseInvoiceItemDetails(position: 1, name: 'Kaucja', netAmount: 500),
        ]);

        $items = $this->items->findByInvoice(Uuid::fromString($invoiceId));
        self::assertCount(2, $items);
        self::assertSame([1, 2], array_map(static fn (PurchasedItem $item): int => $item->getItemIndex(), $items));
        self::assertSame(['Jack Daniel\'s 0,7l', 'Kaucja'], array_map(static fn (PurchasedItem $item): string => $item->getName(), $items));
        self::assertSame('6', $items[0]->getQuantity());
        self::assertSame('81.30081301', $items[0]->getUnitNetPrice());
        self::assertSame(48_780, $items[0]->getNetAmount());
        self::assertSame('2026-11-10', $items[0]->getInvoiceIssueDate()->toString());
        self::assertSame('regular', $items[0]->getInvoiceKind());
        self::assertSame('PLN', $items[0]->getCurrency());
        self::assertSame('5265877635', $items[0]->getSellerTaxId());
        self::assertSame('Hurtownia', $items[0]->getSellerName());
        self::assertTrue($items[0]->getOrganization()?->getId()->equals($organization->getId()));
        foreach ($items as $item) {
            self::assertSame(PurchasedItemStatus::Unassigned, $item->getStatus());
            self::assertNull($item->getProductId());
            self::assertNull($item->getPackageSize());
        }
    }

    public function test_importing_an_invoice_without_items_records_nothing(): void
    {
        $organization = $this->switchToNewOrganization();
        $invoiceId = Uuid::v7()->toRfc4122();

        $this->import($organization, $invoiceId, 'K-1', []);

        self::assertSame([], $this->items->findByInvoice(Uuid::fromString($invoiceId)));
    }

    public function test_reading_the_items_of_a_stored_invoice_again_records_them_too_and_only_once(): void
    {
        $organization = $this->switchToNewOrganization();
        $invoiceId = Uuid::v7()->toRfc4122();
        $this->import($organization, $invoiceId, 'K-1', []);
        $organizationId = $organization->getId()->toRfc4122();

        $this->commandBus->handle(RecordPurchaseInvoiceItemsCommand::of($organizationId, $invoiceId, [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo', quantity: '4')]));
        $this->commandBus->handle(RecordPurchaseInvoiceItemsCommand::of($organizationId, $invoiceId, [new PurchaseInvoiceItemDetails(position: 1, name: 'Wino')]));

        $items = $this->items->findByInvoice(Uuid::fromString($invoiceId));
        self::assertCount(1, $items);
        self::assertSame('Piwo', $items[0]->getName());
    }

    public function test_another_organizations_invoice_items_are_not_visible(): void
    {
        $mine = $this->switchToNewOrganization();
        $myInvoice = Uuid::v7()->toRfc4122();
        $this->import($mine, $myInvoice, 'K-1', [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]);

        $other = $this->switchToNewOrganization();
        $otherInvoice = Uuid::v7()->toRfc4122();
        $this->import($other, $otherInvoice, 'K-1', [new PurchaseInvoiceItemDetails(position: 1, name: 'Wino')]);

        self::assertSame([], $this->items->findByInvoice(Uuid::fromString($myInvoice)));
        self::assertSame(['Wino'], array_map(static fn (PurchasedItem $item): string => $item->getName(), $this->items->findByInvoice(Uuid::fromString($otherInvoice))));
        $this->tenantContext->switchTo($mine);
        self::assertSame(['Piwo'], array_map(static fn (PurchasedItem $item): string => $item->getName(), $this->items->findByInvoice(Uuid::fromString($myInvoice))));
        self::assertSame([], $this->items->findByInvoice(Uuid::fromString($otherInvoice)));
    }

    /** @param list<PurchaseInvoiceItemDetails> $items */
    private function import(Organization $organization, string $invoiceId, string $externalId, array $items): void
    {
        $details = new PurchaseInvoiceDetails('FV/1/2026', '5265877635', 'Hurtownia', BusinessDate::fromString('2026-11-10'), PurchaseInvoiceKind::Regular, 'PLN', 20_000, 4_600, 24_600, [new PurchaseInvoiceVatAmount('23', 20_000, 4_600)], items: $items);

        $this->commandBus->handle(ImportPurchaseInvoiceCommand::of($organization->getId()->toRfc4122(), $invoiceId, 'ksef', $externalId, $details, '<Faktura/>', 'FA (3)'));
    }

    private function switchToNewOrganization(): Organization
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);

        return $organization;
    }
}
