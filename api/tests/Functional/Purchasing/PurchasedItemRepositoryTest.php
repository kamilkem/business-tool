<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemFilter;
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

    public function test_it_finds_an_item_and_items_by_ids_in_the_current_organization_only(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $mine = [self::item(Uuid::v7(), 1), self::item(Uuid::v7(), 1)];
        $this->items->addAll($mine);
        $this->entityManager->clear();
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $theirs = self::item(Uuid::v7(), 1);
        $this->items->addAll([$theirs]);
        $this->entityManager->clear();

        self::assertNull($this->items->findById($mine[0]->getId()));
        self::assertSame([], $this->items->findByIds([$mine[0]->getId(), $mine[1]->getId()]));
        self::assertSame([], $this->items->findByIds([]));
        self::assertCount(1, $this->items->findByIds([$mine[0]->getId(), $theirs->getId(), Uuid::v7()]));
        self::assertTrue($this->items->findById($theirs->getId())?->getId()->equals($theirs->getId()) ?? false);
    }

    public function test_it_stores_a_link_to_a_product_with_its_status_and_package_size(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $product = $this->product('Jack Daniel\'s');
        $item = self::item(Uuid::v7(), 1);
        $this->items->addAll([$item]);
        $item->link($product, '0.70', Uuid::v7(), MembershipRole::Owner);
        $this->items->save($item);
        $this->entityManager->clear();

        $stored = $this->items->findById($item->getId()) ?? self::fail('Item expected.');

        self::assertSame(PurchasedItemStatus::Confirmed, $stored->getStatus());
        self::assertTrue($stored->getProductId()?->equals($product->getId()) ?? false);
        self::assertSame('Jack Daniel\'s', $stored->getProduct()?->getName());
        self::assertSame('0.7', $stored->getPackageSize());
        self::assertTrue($this->items->hasItemsOfProduct($product->getId()));

        $stored->link(null, '0.7', Uuid::v7(), MembershipRole::Owner);
        $this->items->save($stored);
        $this->entityManager->clear();

        self::assertSame(PurchasedItemStatus::Unassigned, ($this->items->findById($item->getId()) ?? self::fail('Item expected.'))->getStatus());
        self::assertFalse($this->items->hasItemsOfProduct($product->getId()));
    }

    public function test_it_saves_several_items_in_one_flush(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $product = $this->product('Cola');
        $items = [self::item(Uuid::v7(), 1), self::item(Uuid::v7(), 1), self::item(Uuid::v7(), 1)];
        $this->items->addAll($items);
        foreach ($items as $item) {
            $item->link($product, '1', Uuid::v7(), MembershipRole::Owner);
        }
        $this->items->saveAll($items);
        $this->entityManager->clear();

        self::assertSame(3, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM purchased_item WHERE status = 'confirmed' AND product_id = :product", ['product' => $product->getId()->toRfc4122()]));
    }

    public function test_it_searches_items_newest_issue_date_first_in_stable_pages_with_a_total(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $oldInvoice = Uuid::v7();
        $newInvoice = Uuid::v7();
        $this->items->addAll([
            self::item($oldInvoice, 1, issueDate: '2026-10-01', name: 'Old 1'),
            self::item($newInvoice, 2, issueDate: '2026-11-20', name: 'New 2'),
            self::item($newInvoice, 1, issueDate: '2026-11-20', name: 'New 1'),
            self::item(Uuid::v7(), 1, issueDate: '2026-11-20', name: 'Alpha', sellerName: 'Aaa Seller'),
        ]);
        $this->entityManager->clear();
        $all = new PurchasedItemFilter();

        self::assertSame(['Alpha', 'New 1', 'New 2', 'Old 1'], self::names($this->items->search($all, 0, 10)));
        self::assertSame(['New 1', 'New 2'], self::names($this->items->search($all, 1, 2)));
        self::assertSame(['Old 1'], self::names($this->items->search($all, 3, 2)));
        self::assertSame(4, $this->items->countMatching($all));
    }

    public function test_it_filters_items_by_status_product_dates_and_name(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $jack = $this->product('Jack Daniel\'s');
        $cola = $this->product('Cola');
        $unassigned = self::item(Uuid::v7(), 1, issueDate: '2026-11-01', name: '100% Juice_Mix');
        $suggested = self::item(Uuid::v7(), 1, issueDate: '2026-11-10', name: 'JACK DANIELS 0,7L');
        $confirmed = self::item(Uuid::v7(), 1, issueDate: '2026-11-20', name: 'Cola 1l');
        $this->items->addAll([$unassigned, $suggested, $confirmed]);
        $suggested->link($jack, '0.7', Uuid::v7(), MembershipRole::Owner);
        $confirmed->link($cola, '1', Uuid::v7(), MembershipRole::Owner);
        $this->items->saveAll([$suggested, $confirmed]);
        // What matching (#233) will do: it suggests, a person confirms.
        $this->entityManager->getConnection()->executeStatement("UPDATE purchased_item SET status = 'suggested' WHERE id = :id", ['id' => $suggested->getId()->toRfc4122()]);
        $this->entityManager->clear();

        self::assertSame(['Cola 1l'], self::names($this->items->search(self::filter([PurchasedItemStatus::Confirmed]), 0, 10)));
        self::assertSame(['JACK DANIELS 0,7L', '100% Juice_Mix'], self::names($this->items->search(self::filter([PurchasedItemStatus::Unassigned, PurchasedItemStatus::Suggested]), 0, 10)));
        self::assertSame(['JACK DANIELS 0,7L'], self::names($this->items->search(self::filter(product: $jack->getId()), 0, 10)));
        self::assertSame(['Cola 1l', 'JACK DANIELS 0,7L'], self::names($this->items->search(self::filter(from: '2026-11-10'), 0, 10)));
        self::assertSame(['JACK DANIELS 0,7L'], self::names($this->items->search(self::filter(from: '2026-11-10', to: '2026-11-19'), 0, 10)), 'Both dates are inclusive.');
        self::assertSame(['100% Juice_Mix'], self::names($this->items->search(self::filter(to: '2026-11-01'), 0, 10)));
        self::assertSame(['JACK DANIELS 0,7L'], self::names($this->items->search(self::filter(name: 'daniels'), 0, 10)));
        self::assertSame(['100% Juice_Mix'], self::names($this->items->search(self::filter(name: '0% juice_'), 0, 10)));
        // A wildcard in the search is a letter, not a pattern: only the item that has a % matches it.
        self::assertSame(['100% Juice_Mix'], self::names($this->items->search(self::filter(name: '%'), 0, 10)));
        self::assertSame([], self::names($this->items->search(self::filter(name: 'c_l'), 0, 10)));
        self::assertSame(1, $this->items->countMatching(self::filter(statuses: [PurchasedItemStatus::Confirmed], from: '2026-11-01')));
        self::assertSame(0, $this->items->countMatching(self::filter([PurchasedItemStatus::Assigned])));
    }

    public function test_it_searches_and_counts_only_the_current_organizations_items(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->items->addAll([self::item(Uuid::v7(), 1, name: 'Mine')]);
        $this->entityManager->clear();
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->items->addAll([self::item(Uuid::v7(), 1, name: 'Theirs 1'), self::item(Uuid::v7(), 1, name: 'Theirs 2')]);
        $this->entityManager->clear();

        self::assertSame(['Theirs 1', 'Theirs 2'], self::names($this->items->search(new PurchasedItemFilter(), 0, 10)));
        self::assertSame(2, $this->items->countMatching(new PurchasedItemFilter()));

        $this->tenantContext->reset();
        self::assertSame(0, $this->items->countMatching(new PurchasedItemFilter()), 'Without an organization nothing is visible.');
    }

    /** @param list<PurchasedItemStatus> $statuses */
    private static function filter(array $statuses = [], ?Uuid $product = null, ?string $from = null, ?string $to = null, ?string $name = null): PurchasedItemFilter
    {
        return new PurchasedItemFilter($statuses, $product, null === $from ? null : BusinessDate::fromString($from), null === $to ? null : BusinessDate::fromString($to), $name);
    }

    private function product(string $name): Product
    {
        $product = Product::create(Uuid::v7(), $name, null, ProductMeasure::Volume, null, false, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));
        self::getContainer()->get(ProductRepositoryInterface::class)->add($product);

        return $product;
    }

    /**
     * @param list<PurchasedItem> $items
     *
     * @return list<string>
     */
    private static function names(array $items): array
    {
        return array_map(static fn (PurchasedItem $item): string => $item->getName(), $items);
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

    private static function item(Uuid $invoiceId, int $index, int $position = 1, string $issueDate = '2026-11-10', string $name = 'Piwo', string $sellerName = 'Hurtownia'): PurchasedItem
    {
        return PurchasedItem::record(Uuid::v7(), self::details($invoiceId, $index, position: $position, name: $name, issueDate: $issueDate, sellerName: $sellerName), new \DateTimeImmutable('2026-11-25 03:00:00'));
    }

    private static function details(Uuid $invoiceId, int $index, int $position = 1, string $name = 'Piwo', ?string $sellerTaxId = '5265877635', string $issueDate = '2026-11-10', string $sellerName = 'Hurtownia'): PurchasedItemDetails
    {
        return new PurchasedItemDetails($invoiceId, $index, BusinessDate::fromString($issueDate), 'regular', 'PLN', $sellerTaxId, $sellerName, $position, $name, null, null, null, null, null, null, null, null, null, null, null, false);
    }
}
