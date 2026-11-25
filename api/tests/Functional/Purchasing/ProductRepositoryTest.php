<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Exception\ProductCategoryInUseException;
use App\Purchasing\Domain\Exception\ProductInUseException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Purchasing\Infrastructure\Doctrine\Orm\Repository\ProductRepository;
use App\Purchasing\Infrastructure\Doctrine\Orm\Repository\PurchasedItemRepository;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * ProductRepository and the product side of PurchasedItemRepository against PostgreSQL: unique names,
 * the filters of the list, the foreign keys that stop a product or category being deleted while
 * something refers to it, the move of a merge and the TenantFilter.
 */
#[CoversClass(ProductRepository::class)]
#[CoversClass(PurchasedItemRepository::class)]
final class ProductRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private ProductRepositoryInterface $products;
    private ProductCategoryRepositoryInterface $categories;
    private PurchasedItemRepositoryInterface $items;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->products = self::getContainer()->get(ProductRepositoryInterface::class);
        $this->categories = self::getContainer()->get(ProductCategoryRepositoryInterface::class);
        $this->items = self::getContainer()->get(PurchasedItemRepositoryInterface::class);
    }

    public function test_it_stores_a_product_with_every_field_in_the_current_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $whisky = $this->category('Whisky');
        $this->categories->add($whisky);
        $product = $this->product('Jack Daniel\'s', $whisky, ProductMeasure::Volume, '0.7', true);
        $this->products->add($product);
        $rent = $this->product('Rent', null, ProductMeasure::None, null);
        $this->products->add($rent);
        $this->entityManager->clear();

        $found = $this->products->findById($product->getId());

        self::assertNotNull($found);
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));
        self::assertSame('Jack Daniel\'s', $found->getName());
        self::assertTrue($found->getCategory()?->getId()->equals($whisky->getId()));
        self::assertSame(ProductMeasure::Volume, $found->getMeasure());
        self::assertSame('0.7', $found->getReferenceQuantity());
        self::assertTrue($found->isExcluded());
        self::assertSame('2026-11-13 10:00:00', $found->getCreatedAt()->format('Y-m-d H:i:s'));
        $foundRent = $this->products->findById($rent->getId());
        self::assertNotNull($foundRent);
        self::assertSame([ProductMeasure::None, null, null], [$foundRent->getMeasure(), $foundRent->getReferenceQuantity(), $foundRent->getCategory()]);
    }

    public function test_it_finds_a_product_by_name_case_insensitively(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $cola = $this->product('Cola Zero');
        $this->products->add($cola);

        self::assertSame($cola, $this->products->findByName(' COLA zero '));
        self::assertNull($this->products->findByName('Cola'));
    }

    public function test_it_refuses_a_name_stored_in_the_meantime(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->products->add($this->product('Cola'));

        try {
            $this->products->add($this->product('COLA'));
            self::fail('The name should be taken.');
        } catch (ProductNameTakenException $e) {
            self::assertSame(['name' => 'COLA'], $e->errorParameters());
        }
    }

    public function test_it_allows_a_name_another_organization_has(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->tenantContext->switchTo($a);
        $this->products->add($this->product('Cola'));
        $this->entityManager->clear();

        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $b->getId()) ?? self::fail('Organization expected.'));
        $this->products->add($this->product('Cola'));

        self::assertCount(1, $this->products->search(null, null));
    }

    public function test_it_refuses_a_rename_to_a_name_stored_in_the_meantime(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->products->add($this->product('Cola'));
        $fanta = $this->product('Fanta');
        $this->products->add($fanta);

        $fanta->change('cola', null, ProductMeasure::Volume, '1', false, null, Uuid::v7(), MembershipRole::Owner);

        $this->expectException(ProductNameTakenException::class);

        $this->products->save($fanta);
    }

    public function test_it_searches_by_categories_and_by_name_ordered_by_name(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $whisky = $this->category('Whisky');
        $food = $this->category('Food');
        $this->categories->add($whisky);
        $this->categories->add($food);
        foreach ([['jack', $whisky], ['Cola', null], ['Beer', $food], ['Apple_pie', $food], ['100% juice', null]] as [$name, $category]) {
            $this->products->add($this->product($name, $category));
        }
        $this->entityManager->clear();

        self::assertSame(['100% juice', 'Apple_pie', 'Beer', 'Cola', 'jack'], $this->names(null, null));
        self::assertSame(['Apple_pie', 'Beer', 'jack'], $this->names([$whisky->getId(), $food->getId()], null));
        self::assertSame(['jack'], $this->names([$whisky->getId()], null));
        self::assertSame([], $this->names([], null));
        self::assertSame([], $this->names([Uuid::v7()], null));
        self::assertSame(['Apple_pie', 'Beer'], $this->names([$food->getId()], ''));
        self::assertSame(['Apple_pie'], $this->names(null, 'E_P'));
        self::assertSame(['100% juice'], $this->names(null, '%'));
        self::assertSame(['Apple_pie'], $this->names(null, '_'));
        self::assertSame([], $this->names(null, 'a!b'));
        self::assertSame(['Cola'], $this->names(null, ' COL '));
    }

    public function test_it_tells_whether_a_category_has_products(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $whisky = $this->category('Whisky');
        $food = $this->category('Food');
        $this->categories->add($whisky);
        $this->categories->add($food);
        $this->products->add($this->product('Jack', $whisky));

        self::assertTrue($this->products->existsInCategory($whisky->getId()));
        self::assertFalse($this->products->existsInCategory($food->getId()));
    }

    public function test_it_refuses_to_delete_a_category_a_product_was_put_in_meanwhile(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $whisky = $this->category('Whisky');
        $this->categories->add($whisky);
        $this->products->add($this->product('Jack', $whisky));
        $this->entityManager->clear();
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? self::fail('Organization expected.'));

        try {
            // The handler would have asked ProductRepository::existsInCategory() first: this is the foreign key alone.
            $this->categories->remove($this->categories->findById($whisky->getId()) ?? self::fail('Category expected.'));
            self::fail('The category has a product.');
        } catch (ProductCategoryInUseException $e) {
            self::assertSame('purchasing.product_category_in_use.has_products', $e->errorCode());
        }
    }

    public function test_it_deletes_a_product_and_refuses_one_an_item_refers_to(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $free = $this->product('Rent', null, ProductMeasure::None, null);
        $used = $this->product('Cola');
        $this->products->add($free);
        $this->products->add($used);
        $itemId = $this->recordItem();
        $this->link($itemId, $used);
        $this->entityManager->clear();
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? self::fail('Organization expected.'));

        self::assertTrue($this->items->hasItemsOfProduct($used->getId()));
        self::assertFalse($this->items->hasItemsOfProduct($free->getId()));
        $this->products->remove($this->products->findById($free->getId()) ?? self::fail('Product expected.'));
        self::assertNull($this->products->findById($free->getId()));

        $this->expectException(ProductInUseException::class);

        $this->products->remove($this->products->findById($used->getId()) ?? self::fail('Product expected.'));
    }

    public function test_it_moves_the_items_of_a_product_keeping_their_status_and_package_size(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $from = $this->product('J. Daniels');
        $to = $this->product('Jack Daniel\'s');
        $other = $this->product('Cola');
        foreach ([$from, $to, $other] as $product) {
            $this->products->add($product);
        }
        $first = $this->recordItem(1);
        $second = $this->recordItem(2);
        $third = $this->recordItem(3);
        $unlinked = $this->recordItem(4);
        $this->link($first, $from, 'confirmed', '1');
        $this->link($second, $from, 'suggested', '0.5');
        $this->link($third, $other, 'assigned', '0.33');
        $this->entityManager->clear();
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? self::fail('Organization expected.'));

        $this->items->moveItems($from->getId(), $to->getId());

        self::assertEquals([
            $first => [$to->getId()->toRfc4122(), 'confirmed', '1.000000'],
            $second => [$to->getId()->toRfc4122(), 'suggested', '0.500000'],
            $third => [$other->getId()->toRfc4122(), 'assigned', '0.330000'],
            $unlinked => [null, 'unassigned', null],
        ], $this->linked());
        self::assertFalse($this->items->hasItemsOfProduct($from->getId()));
        $this->products->remove($this->products->findById($from->getId()) ?? self::fail('Product expected.'));
        self::assertNull($this->products->findById($from->getId()));
    }

    public function test_it_reads_an_items_product_through_the_association(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $product = $this->product('Cola');
        $this->products->add($product);
        $itemId = $this->recordItem();
        $this->link($itemId, $product);
        $this->entityManager->clear();
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? self::fail('Organization expected.'));

        $item = $this->entityManager->find(PurchasedItem::class, Uuid::fromString($itemId));

        self::assertTrue($item?->getProductId()?->equals($product->getId()));
        self::assertSame(PurchasedItemStatus::Confirmed, $item->getStatus());
        self::assertSame('1', $item->getPackageSize());
    }

    public function test_it_does_not_show_another_organizations_products(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->tenantContext->switchTo($b);
        $foreign = $this->product('Cola');
        $this->products->add($foreign);
        $this->entityManager->clear();

        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $a->getId()) ?? self::fail('Organization expected.'));

        self::assertNull($this->products->findById($foreign->getId()));
        self::assertNull($this->products->findByName('Cola'));
        self::assertSame([], $this->products->search(null, null));
    }

    /**
     * @param list<Uuid>|null $categoryIds
     *
     * @return list<string>
     */
    private function names(?array $categoryIds, ?string $search): array
    {
        return array_map(static fn (Product $product): string => $product->getName(), $this->products->search($categoryIds, $search));
    }

    private function product(string $name, ?ProductCategory $category = null, ProductMeasure $measure = ProductMeasure::Volume, ?string $referenceQuantity = null, bool $excluded = false): Product
    {
        return Product::create(Uuid::v7(), $name, $category, $measure, $referenceQuantity, $excluded, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-13 10:00:00'));
    }

    private function category(string $name): ProductCategory
    {
        return ProductCategory::create(Uuid::v7(), $name, null, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-13 10:00:00'));
    }

    /** Records an item in the current organization and returns its id. */
    private function recordItem(int $index = 1): string
    {
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), $index, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', null, 'Hurtownia',
            $index, 'Item '.$index, null, null, 'szt.', '1', '10', '12.3', null, 1_000, 1_230, 230, '23', false,
        ), new \DateTimeImmutable('2026-11-13 10:00:00'));
        $this->items->addAll([$item]);

        return $item->getId()->toRfc4122();
    }

    /** Links an item the way #242 will: its product, status and package size. */
    private function link(string $itemId, Product $product, string $status = 'confirmed', string $packageSize = '1'): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE purchased_item SET product_id = :product, status = :status, package_size = :size WHERE id = :id',
            ['product' => $product->getId()->toRfc4122(), 'status' => $status, 'size' => $packageSize, 'id' => $itemId],
        );
    }

    /** @return array<string, array{?string, string, ?string}> product id, status and package size by item id */
    private function linked(): array
    {
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT id, product_id, status, package_size FROM purchased_item');

        return array_column(array_map(static fn (array $row): array => [$row['id'], [$row['product_id'], $row['status'], $row['package_size']]], $rows), 1, 0);
    }
}
