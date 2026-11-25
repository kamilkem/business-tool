<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Purchasing\Infrastructure\Doctrine\Orm\Repository\PurchasedItemRepository;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * PurchasedItemRepository against PostgreSQL: every copied field comes back as stored (exact
 * decimals, BIGINT and negative amounts, the issue date as a DATE), one row per (invoice, item
 * index), all or none per flush, and the TenantFilter.
 */
#[CoversClass(PurchasedItemRepository::class)]
#[CoversClass(PurchasedItem::class)]
final class PurchasedItemRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private PurchasedItemRepositoryInterface $items;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->items = self::getContainer()->get(PurchasedItemRepositoryInterface::class);
    }

    public function test_it_stores_an_item_with_every_field_exactly_in_the_current_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $invoiceId = Uuid::v7();
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            $invoiceId, 3, BusinessDate::fromString('2026-11-10'), 'correction', 'EUR', '5265877635', 'Hurtownia Napojów',
            7, 'Jack Daniel\'s 0,7l', 'JD-07', '5099873089798', 'szt.', '0.7', '12.34567891', '15.18518518', '-0.5',
            -3_150, 3_875, 725, '23', true,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
        $this->items->addAll([$item]);
        $this->entityManager->clear();

        $found = $this->items->findByInvoice($invoiceId);

        self::assertCount(1, $found);
        $stored = $found[0];
        self::assertTrue($stored->getId()->equals($item->getId()));
        self::assertTrue($stored->getOrganization()?->getId()->equals($organization->getId()));
        self::assertTrue($stored->getInvoiceId()->equals($invoiceId));
        self::assertSame(3, $stored->getItemIndex());
        self::assertSame('2026-11-10', $stored->getInvoiceIssueDate()->toString());
        self::assertSame('correction', $stored->getInvoiceKind());
        self::assertSame('EUR', $stored->getCurrency());
        self::assertSame('5265877635', $stored->getSellerTaxId());
        self::assertSame('Hurtownia Napojów', $stored->getSellerName());
        self::assertSame(7, $stored->getPosition());
        self::assertSame('Jack Daniel\'s 0,7l', $stored->getName());
        self::assertSame('JD-07', $stored->getSellerCode());
        self::assertSame('5099873089798', $stored->getGtin());
        self::assertSame('szt.', $stored->getUnit());
        self::assertSame('0.7', $stored->getQuantity());
        self::assertSame('12.34567891', $stored->getUnitNetPrice());
        self::assertSame('15.18518518', $stored->getUnitGrossPrice());
        self::assertSame('-0.5', $stored->getDiscount());
        self::assertSame(-3_150, $stored->getNetAmount());
        self::assertSame(3_875, $stored->getGrossAmount());
        self::assertSame(725, $stored->getVatAmount());
        self::assertSame('23', $stored->getVatRate());
        self::assertTrue($stored->isBeforeCorrection());
        self::assertNull($stored->getProductId());
        self::assertSame(PurchasedItemStatus::Unassigned, $stored->getStatus());
        self::assertNull($stored->getPackageSize());
        self::assertSame('2026-11-25 03:00:00', $stored->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function test_it_stores_an_item_with_only_what_an_invoice_must_print(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $invoiceId = Uuid::v7();
        $this->items->addAll([PurchasedItem::record(Uuid::v7(), self::details($invoiceId, 1, name: 'Opłata', sellerTaxId: null), new \DateTimeImmutable('2026-11-25 03:00:00'))]);
        $this->entityManager->clear();

        $stored = $this->items->findByInvoice($invoiceId)[0];

        self::assertNull($stored->getSellerTaxId());
        self::assertNull($stored->getSellerCode());
        self::assertNull($stored->getGtin());
        self::assertNull($stored->getUnit());
        self::assertNull($stored->getQuantity());
        self::assertNull($stored->getUnitNetPrice());
        self::assertNull($stored->getUnitGrossPrice());
        self::assertNull($stored->getDiscount());
        self::assertNull($stored->getNetAmount());
        self::assertNull($stored->getGrossAmount());
        self::assertNull($stored->getVatAmount());
        self::assertNull($stored->getVatRate());
        self::assertFalse($stored->isBeforeCorrection());
    }

    public function test_it_finds_the_items_of_one_invoice_by_index(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $invoiceId = Uuid::v7();
        $other = Uuid::v7();
        $this->items->addAll([
            self::item($invoiceId, 3),
            self::item($invoiceId, 1),
            self::item($other, 1),
            self::item($invoiceId, 2),
        ]);
        $this->entityManager->clear();

        self::assertSame([1, 2, 3], array_map(static fn (PurchasedItem $item): int => $item->getItemIndex(), $this->items->findByInvoice($invoiceId)));
        self::assertCount(1, $this->items->findByInvoice($other));
        self::assertSame([], $this->items->findByInvoice(Uuid::v7()));
    }

    public function test_it_stores_nothing_for_no_items(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());

        $this->items->addAll([]);

        self::assertSame([], $this->items->findByInvoice(Uuid::v7()));
    }

    public function test_it_allows_the_same_printed_position_twice_on_one_invoice(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $invoiceId = Uuid::v7();

        $this->items->addAll([self::item($invoiceId, 1, position: 5), self::item($invoiceId, 2, position: 5)]);
        $this->entityManager->clear();

        self::assertCount(2, $this->items->findByInvoice($invoiceId));
    }

    public function test_it_rejects_an_item_index_recorded_twice_for_an_invoice_and_stores_none_of_the_batch(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $invoiceId = Uuid::v7();
        $this->items->addAll([self::item($invoiceId, 1)]);

        try {
            // A concurrent writer that did not see item 1: only the constraint can stop it, and it takes item 2 down with it.
            $this->items->addAll([self::item($invoiceId, 2), self::item($invoiceId, 1)]);
            self::fail('The unique constraint should refuse the second item 1.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertStringContainsString(PurchasedItem::ONE_PER_INVOICE_ITEM, $e->getMessage());
        }

        self::assertSame([1], array_map(static fn (PurchasedItem $item): int => $item->getItemIndex(), $this->freshItems($invoiceId)));
    }

    public function test_it_does_not_show_another_organizations_items(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $mine = Uuid::v7();
        $this->items->addAll([self::item($mine, 1)]);
        $this->entityManager->clear();

        $other = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($other);
        $theirs = Uuid::v7();
        $this->items->addAll([self::item($theirs, 1), self::item($theirs, 2)]);
        $this->entityManager->clear();

        self::assertSame([], $this->items->findByInvoice($mine));
        self::assertCount(2, $this->items->findByInvoice($theirs));

        $this->tenantContext->reset();
        self::assertSame([], $this->items->findByInvoice($theirs), 'Without an organization nothing is visible.');
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM purchased_item WHERE organization_id = :id AND invoice_id = :invoice', ['id' => Uuid::v7()->toRfc4122(), 'invoice' => $mine->toRfc4122()]));
    }

    public function test_it_removes_the_items_with_their_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $this->items->addAll([self::item(Uuid::v7(), 1)]);
        $this->entityManager->clear();
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM purchased_item'));

        $this->entityManager->getConnection()->executeStatement('DELETE FROM organization WHERE id = :id', ['id' => $organization->getId()->toRfc4122()]);

        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM purchased_item'));
    }

    /** @return list<PurchasedItem> */
    private function freshItems(Uuid $invoiceId): array
    {
        // A failed flush closes the entity manager: read through a new one.
        $organizationId = $this->tenantContext->getOrganization()->getId();
        self::getContainer()->get('doctrine')->resetManager();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $organization = $entityManager->find(Organization::class, $organizationId) ?? self::fail('Organization expected.');
        $this->tenantContext->switchTo($organization);

        return self::getContainer()->get(PurchasedItemRepositoryInterface::class)->findByInvoice($invoiceId);
    }

    private static function item(Uuid $invoiceId, int $index, int $position = 1): PurchasedItem
    {
        return PurchasedItem::record(Uuid::v7(), self::details($invoiceId, $index, position: $position), new \DateTimeImmutable('2026-11-25 03:00:00'));
    }

    private static function details(Uuid $invoiceId, int $index, int $position = 1, string $name = 'Piwo', ?string $sellerTaxId = '5265877635'): PurchasedItemDetails
    {
        return new PurchasedItemDetails($invoiceId, $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', $sellerTaxId, 'Hurtownia', $position, $name, null, null, null, null, null, null, null, null, null, null, null, false);
    }
}
