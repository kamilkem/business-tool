<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Analysis\PricePoint;
use App\Purchasing\Domain\Analysis\ProductSpend;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchaseAnalysisRepositoryInterface;
use App\Purchasing\Infrastructure\Doctrine\Orm\Repository\PurchaseAnalysisRepository;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\OrganizationMother;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The purchase analysis SQL (ADR 0041 §6–8) against PostgreSQL, on one fixed data set (see
 * arrange()) that covers each rule: package sizes compared per 1 L and per a reference quantity of
 * 0.5 L, a correction, a gross-only item, a foreign currency, an excluded product, a product without
 * a unit, a three-level category tree, the change against a previous purchase from another seller,
 * and another organization's items.
 *
 * The data, in PLN, with the period 2026-11-01..2026-11-30 (net / gross in minor units):
 *
 *  Jack Daniel's (volume, per 1 L, Alkohol › Whisky › Bourbon)
 *   - 10-20 seller A: 6 × 0.7 L (4.2 L)  12 600 / 15 498  → 3 000 / 3 690 per L (before the period)
 *   - 11-05 seller B: 1 × 1 L             3 300 /  4 059  → 3 300 / 4 059 per L
 *   - 11-12 seller A: 2 × 1 L, gross only      – /  8 000  → – / 4 000 per L
 *   - 11-20 correction: the row before 3 300 / 4 059, the row after 3 000 / 3 690 (no price points)
 *   - 11-25: 3 × unknown size             9 000 / 11 070  (spend only)
 *   - 11-26: in EUR                        500 /    615  (left out)
 *  Piwo (volume, per 0.5 L, Napoje)
 *   - 11-08: 20 × 0.5 L                  40 000 / 49 200  → 2 000 / 2 460 per 0.5 L
 *   - 11-15: 1 × 30 L keg                54 000 / 66 420  →   900 / 1 107 per 0.5 L
 *  Czynsz (none, no category), spend only: 650 000 / 799 500 printed; – / 123 000 with 23 000 VAT;
 *    10 000 / – with 2 300 VAT
 *  Kaucja (none, excluded)                  1 000 /  1 230 (left out on purpose)
 *  Not assigned: 11-18                      2 000 /  2 460; and 10-01, outside the period
 */
#[CoversClass(PurchaseAnalysisRepository::class)]
final class PurchaseAnalysisRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private TenantContextInterface $tenantContext;
    private PurchaseAnalysisRepositoryInterface $analysis;
    private Connection $connection;
    private Organization $organization;
    private int $stored = 0;

    /** @var array<string, string> product and category ids by name */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->analysis = self::getContainer()->get(PurchaseAnalysisRepositoryInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($this->organization);
        $this->arrange();
    }

    public function test_it_sums_the_spend_of_a_period_on_both_bases(): void
    {
        $analysis = $this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), 'PLN');

        $products = self::byName($analysis->products);
        // Jack: 3 300 + 0 (gross only) + (−3 300 + 3 000 of the correction) + 9 000; the gross 4 059 + 8 000 + (−4 059 + 3 690) + 11 070.
        self::assertSame([12_000, 22_760], [$products['Jack Daniel\'s']->spend->net, $products['Jack Daniel\'s']->spend->gross]);
        self::assertSame([94_000, 115_620], [$products['Piwo']->spend->net, $products['Piwo']->spend->gross]);
        // Czynsz: 650 000 / 799 500 printed, 123 000 gross less 23 000 VAT = 100 000 / 123 000, and 10 000 net plus 2 300 VAT = 10 000 / 12 300.
        self::assertSame([760_000, 934_800], [$products['Czynsz']->spend->net, $products['Czynsz']->spend->gross]);
        self::assertSame(['Czynsz', 'Jack Daniel\'s', 'Piwo'], array_keys($products), 'The excluded product is left out; the rest are by name.');
    }

    public function test_it_compares_prices_per_the_reference_quantity_whatever_the_package_size(): void
    {
        $products = self::byName($this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), 'PLN')->products);

        $beer = $products['Piwo'];
        self::assertSame('0.5', $beer->referenceQuantity);
        // 20 × 0.5 L and a 30 L keg are 40 L: 80 reference quantities of 0.5 L.
        self::assertSame('80', $beer->quantity);
        // 94 000 ÷ 40 L × 0.5 L = 1 175; gross 115 620 ÷ 40 × 0.5 = 1 445.25.
        self::assertSame([1_175, 1_445], [$beer->averageNetPrice, $beer->averageGrossPrice]);
        // The keg of seller A is the last purchase: 900 against the 2 000 of seller B's bottles.
        self::assertSame('2026-11-15', $beer->lastPriceDate);
        self::assertNotNull($beer->lastNet);
        self::assertSame([900, -1_100, '-55'], [$beer->lastNet->price, $beer->lastNet->change, $beer->lastNet->changePercent]);
        self::assertNotNull($beer->lastGross);
        self::assertSame([1_107, -1_353, '-55'], [$beer->lastGross->price, $beer->lastGross->change, $beer->lastGross->changePercent]);
    }

    public function test_it_averages_each_basis_over_the_price_points_that_have_it(): void
    {
        $jack = self::byName($this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), 'PLN')->products)['Jack Daniel\'s'];

        // The price points of November are 1 L at 3 300 / 4 059 and 2 L at – / 4 000: no corrections, no unknown size.
        self::assertSame('3', $jack->quantity);
        self::assertSame(3_300, $jack->averageNetPrice, 'Only the point with a net value counts: 3 300 ÷ 1 L.');
        self::assertSame(4_020, $jack->averageGrossPrice, '(4 059 + 8 000) ÷ 3 L = 4 019.67.');
    }

    public function test_it_measures_the_last_price_against_the_previous_purchase_from_another_seller_before_the_period(): void
    {
        $jack = self::byName($this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), 'PLN')->products)['Jack Daniel\'s'];

        self::assertSame('2026-11-12', $jack->lastPriceDate);
        self::assertNull($jack->lastNet, 'The last purchase has no net value.');
        self::assertNotNull($jack->lastGross);
        // 4 000 against the 4 059 of seller B's purchase a week earlier.
        self::assertSame([4_000, -59, '-1.45'], [$jack->lastGross->price, $jack->lastGross->change, $jack->lastGross->changePercent]);

        // For a period that ends with the November 5 purchase, the previous one is the seller A's of October.
        $first = self::byName($this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-10'), 'PLN')->products)['Jack Daniel\'s'];
        self::assertNotNull($first->lastNet);
        self::assertNotNull($first->lastGross);
        self::assertSame([3_300, 300, '10'], [$first->lastNet->price, $first->lastNet->change, $first->lastNet->changePercent]);
        self::assertSame([4_059, 369, '10'], [$first->lastGross->price, $first->lastGross->change, $first->lastGross->changePercent]);
    }

    public function test_it_gives_a_product_without_a_unit_its_spend_only(): void
    {
        $rent = self::byName($this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), 'PLN')->products)['Czynsz'];

        self::assertSame(ProductMeasure::None, $rent->measure);
        self::assertNull($rent->referenceQuantity);
        self::assertNull($rent->quantity);
        self::assertNull($rent->averageNetPrice);
        self::assertNull($rent->averageGrossPrice);
        self::assertNull($rent->lastPriceDate);
        self::assertNull($rent->lastNet);
        self::assertNull($rent->lastGross);
    }

    public function test_it_rolls_the_spend_up_a_three_level_category_tree(): void
    {
        $analysis = $this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), 'PLN');

        $categories = [];
        foreach ($analysis->categories as $category) {
            $categories[$category->name] = $category;
        }
        self::assertSame(['Alkohol', 'Bourbon', 'Napoje', 'Whisky'], array_keys($categories), 'Every category, by name, also the ones without spend.');
        self::assertSame([null, $this->ids['Alkohol'], $this->ids['Whisky'], null], [
            $categories['Alkohol']->parentId, $categories['Whisky']->parentId, $categories['Bourbon']->parentId, $categories['Napoje']->parentId,
        ]);

        foreach (['Bourbon', 'Whisky', 'Alkohol'] as $name) {
            self::assertSame([12_000, 22_760], [$categories[$name]->total->net, $categories[$name]->total->gross], $name.' with what is below it');
        }
        self::assertSame([12_000, 22_760], [$categories['Bourbon']->own->net, $categories['Bourbon']->own->gross]);
        self::assertSame([0, 0], [$categories['Whisky']->own->net, $categories['Alkohol']->own->net]);
        self::assertSame([94_000, 94_000, 115_620], [$categories['Napoje']->total->net, $categories['Napoje']->own->net, $categories['Napoje']->total->gross]);
    }

    public function test_it_counts_what_is_not_assigned_and_what_it_leaves_out(): void
    {
        $analysis = $this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), 'PLN');

        self::assertSame([2_000, 2_460, 1], [$analysis->notAssignedSpend->net, $analysis->notAssignedSpend->gross, $analysis->notAssignedItems]);
        self::assertSame(1, $analysis->otherCurrencyItems, 'The euro item; the excluded product\'s item is not counted.');
        self::assertSame(1, $analysis->itemsWithoutNet, 'The gross-only item.');
        self::assertSame(0, $analysis->itemsWithoutGross);
        self::assertSame(1, $analysis->itemsWithoutPackageSize, 'The 3 items of an unknown size; the unassigned and the correction rows are not.');
    }

    public function test_it_counts_only_the_invoices_issued_in_the_period(): void
    {
        $analysis = $this->analysis->overview(BusinessPeriod::fromStrings('2026-10-01', '2026-10-31'), 'PLN');

        self::assertSame(['Jack Daniel\'s'], array_keys(self::byName($analysis->products)));
        self::assertSame(1, $analysis->notAssignedItems, 'The October 1 item only.');
        $jack = self::byName($analysis->products)['Jack Daniel\'s'];
        self::assertSame([12_600, 15_498], [$jack->spend->net, $jack->spend->gross]);
        self::assertNull($jack->lastNet?->change, 'The first purchase has nothing to change against.');
        self::assertSame(['3000', null], [(string) $jack->averageNetPrice, $jack->lastNet?->changePercent]);
    }

    public function test_it_counts_only_the_organizations_currency(): void
    {
        $analysis = $this->analysis->overview(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), 'EUR');

        self::assertSame(['Jack Daniel\'s'], array_keys(self::byName($analysis->products)));
        self::assertSame([500, 615], [self::byName($analysis->products)['Jack Daniel\'s']->spend->net, self::byName($analysis->products)['Jack Daniel\'s']->spend->gross]);
    }

    public function test_it_lists_the_price_points_oldest_first_with_the_change_against_the_previous_one(): void
    {
        $points = $this->analysis->priceHistory(Uuid::fromString($this->ids['Jack Daniel\'s']), 'PLN', null, null);

        // The corrections, the item of unknown size and the euro item are no price points.
        self::assertSame(['2026-10-20', '2026-11-05', '2026-11-12'], array_map(static fn (PricePoint $point): string => $point->date, $points));
        [$first, $second, $third] = $points;

        self::assertSame('Hurtownia A', $first->sellerName);
        self::assertSame('111', $first->sellerTaxId);
        self::assertSame(['6', '0.7'], [$first->quantity, $first->packageSize]);
        self::assertSame('JACK DANIELS 0,7L', $first->itemName);
        self::assertNotNull($first->net);
        self::assertNotNull($first->gross);
        self::assertSame([3_000, null, null, 3_690, null, null], [$first->net->price, $first->net->change, $first->net->changePercent, $first->gross->price, $first->gross->change, $first->gross->changePercent]);

        self::assertSame('Hurtownia B', $second->sellerName, 'The change is against the previous purchase from any seller.');
        self::assertNotNull($second->net);
        self::assertNotNull($second->gross);
        self::assertSame([3_300, 300, '10', 4_059, 369, '10'], [$second->net->price, $second->net->change, $second->net->changePercent, $second->gross->price, $second->gross->change, $second->gross->changePercent]);

        self::assertNull($third->net, 'A gross-only invoice has no net price.');
        self::assertNotNull($third->gross);
        self::assertSame([4_000, -59, '-1.45'], [$third->gross->price, $third->gross->change, $third->gross->changePercent]);
        self::assertSame('Hurtownia A', $third->sellerName);
        self::assertSame($this->invoices['gross-only'], $third->invoiceId);
    }

    public function test_it_limits_the_history_to_a_period_but_changes_against_the_point_before_it(): void
    {
        $points = $this->analysis->priceHistory(Uuid::fromString($this->ids['Jack Daniel\'s']), 'PLN', BusinessDate::fromString('2026-11-01'), BusinessDate::fromString('2026-11-05'));

        self::assertSame(['2026-11-05'], array_map(static fn (PricePoint $point): string => $point->date, $points));
        self::assertSame(300, $points[0]->net?->change);
        self::assertSame([], $this->analysis->priceHistory(Uuid::fromString($this->ids['Jack Daniel\'s']), 'PLN', BusinessDate::fromString('2026-12-01'), null));
    }

    public function test_it_has_no_price_points_for_products_without_a_unit_or_excluded_ones(): void
    {
        self::assertSame([], $this->analysis->priceHistory(Uuid::fromString($this->ids['Czynsz']), 'PLN', null, null));
        self::assertSame([], $this->analysis->priceHistory(Uuid::fromString($this->ids['Kaucja']), 'PLN', null, null));
    }

    public function test_it_does_not_see_another_organizations_items(): void
    {
        $other = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($other);

        $analysis = $this->analysis->overview(BusinessPeriod::fromStrings('2026-10-01', '2026-11-30'), 'PLN');

        self::assertSame([], $analysis->products);
        self::assertSame([], $analysis->categories);
        self::assertSame([0, 0, 0], [$analysis->notAssignedSpend->net, $analysis->notAssignedSpend->gross, $analysis->notAssignedItems]);
        self::assertSame([0, 0, 0, 0], [$analysis->otherCurrencyItems, $analysis->itemsWithoutNet, $analysis->itemsWithoutGross, $analysis->itemsWithoutPackageSize]);
        self::assertSame([], $this->analysis->priceHistory(Uuid::fromString($this->ids['Jack Daniel\'s']), 'PLN', null, null));
    }

    /** @var array<string, string> invoice ids by label */
    private array $invoices = [];

    private function arrange(): void
    {
        $owner = OrganizationMother::ownerOf($this->organization);
        $now = new \DateTimeImmutable('2026-11-25 03:00:00');
        $categories = self::getContainer()->get(ProductCategoryRepositoryInterface::class);
        $products = self::getContainer()->get(ProductRepositoryInterface::class);

        $category = function (string $name, ?ProductCategory $parent) use ($categories, $owner, $now): ProductCategory {
            $category = ProductCategory::create(Uuid::v7(), $name, $parent, null, $owner->getId(), MembershipRole::Owner, $now);
            $categories->add($category);
            $this->ids[$name] = $category->getId()->toRfc4122();

            return $category;
        };
        $alkohol = $category('Alkohol', null);
        $bourbon = $category('Bourbon', $category('Whisky', $alkohol));
        $napoje = $category('Napoje', null);

        $product = function (string $name, ?ProductCategory $category, ProductMeasure $measure, ?string $reference, bool $excluded = false) use ($products, $owner, $now): Product {
            $product = Product::create(Uuid::v7(), $name, $category, $measure, $reference, $excluded, null, $owner->getId(), MembershipRole::Owner, $now);
            $products->add($product);
            $this->ids[$name] = $product->getId()->toRfc4122();

            return $product;
        };
        $jack = $product('Jack Daniel\'s', $bourbon, ProductMeasure::Volume, '1');
        $beer = $product('Piwo', $napoje, ProductMeasure::Volume, '0.5');
        $rent = $product('Czynsz', null, ProductMeasure::None, null);
        $deposit = $product('Kaucja', null, ProductMeasure::None, null, true);

        $this->item($jack, '2026-10-20', 'A', 'JACK DANIELS 0,7L', '6', '0.7', 12_600, 15_498);
        $this->item($jack, '2026-11-05', 'B', 'Jack Daniel\'s 1 l', '1', '1', 3_300, 4_059);
        $this->invoices['gross-only'] = $this->item($jack, '2026-11-12', 'A', 'Jack Daniel\'s 1 l', '2', '1', null, 8_000);
        $this->item($jack, '2026-11-20', 'A', 'Jack Daniel\'s 1 l', '1', '1', 3_300, 4_059, kind: 'correction', beforeCorrection: true);
        $this->item($jack, '2026-11-20', 'A', 'Jack Daniel\'s 1 l', '1', '1', 3_000, 3_690, kind: 'correction', invoiceId: $this->lastInvoiceId, index: 2);
        $this->item($jack, '2026-11-25', 'A', 'Jack Daniel\'s', '3', null, 9_000, 11_070);
        $this->item($jack, '2026-11-26', 'A', 'Jack Daniel\'s 1 l', '1', '1', 500, 615, currency: 'EUR');
        $this->item($beer, '2026-11-08', 'B', 'Piwo 0,5 l', '20', '0.5', 40_000, 49_200);
        $this->item($beer, '2026-11-15', 'A', 'Piwo keg 30 l', '1', '30', 54_000, 66_420);
        $this->item($rent, '2026-11-02', 'C', 'Czynsz listopad', '1', null, 650_000, 799_500);
        $this->item($rent, '2026-11-03', 'C', 'Czynsz, doliczenie', '1', null, null, 123_000, vat: 23_000);
        $this->item($rent, '2026-11-04', 'C', 'Czynsz, media', '1', null, 10_000, null, vat: 2_300);
        $this->item($deposit, '2026-11-09', 'A', 'Kaucja', '1', null, 1_000, 1_230);
        $this->item(null, '2026-11-18', 'A', 'Coś', '1', null, 2_000, 2_460);
        $this->item(null, '2026-10-01', 'A', 'Coś', '1', null, 7_000, 8_610);

        // Another organization's items are in the same tables.
        $other = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($other);
        $this->item(null, '2026-11-18', 'A', 'Cudze', '1', null, 99_000, 121_770);
        $this->tenantContext->switchTo($this->organization);
    }

    private string $lastInvoiceId = '';

    /** @return string the invoice's id */
    private function item(?Product $product, string $issueDate, string $seller, string $name, string $quantity, ?string $packageSize, ?int $net, ?int $gross, string $kind = 'regular', string $currency = 'PLN', bool $beforeCorrection = false, ?string $invoiceId = null, int $index = 1, ?int $vat = null): string
    {
        $invoiceId ??= Uuid::v7()->toRfc4122();
        $this->lastInvoiceId = $invoiceId;
        $taxIds = ['A' => '111', 'B' => '222', 'C' => '333'];
        $item = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::fromString($invoiceId), $index, BusinessDate::fromString($issueDate), $kind, $currency, $taxIds[$seller], 'Hurtownia '.$seller,
            1, $name, null, null, 'szt.', $quantity, null, null, null, $net, $gross, $vat, null, $beforeCorrection,
        ), new \DateTimeImmutable('2026-11-25 03:00:00')->modify('+'.(++$this->stored).' seconds'));
        self::getContainer()->get(PurchasedItemRepositoryInterface::class)->addAll([$item]);

        $this->connection->executeStatement('UPDATE purchased_item SET product_id = :product, status = :status, package_size = :size WHERE id = :id', [
            'product' => $product?->getId()->toRfc4122(),
            'status' => null === $product ? 'unassigned' : 'confirmed',
            'size' => $packageSize,
            'id' => $item->getId()->toRfc4122(),
        ]);

        return $invoiceId;
    }

    /**
     * @param list<ProductSpend> $products
     *
     * @return array<string, ProductSpend>
     */
    private static function byName(array $products): array
    {
        $byName = [];
        foreach ($products as $product) {
            $byName[$product->name] = $product;
        }

        return $byName;
    }
}
