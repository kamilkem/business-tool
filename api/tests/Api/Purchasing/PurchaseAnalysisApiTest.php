<?php

declare(strict_types=1);

namespace App\Tests\Api\Purchasing;

use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use App\User\Domain\Model\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /purchase-analysis and GET /products/{id}/price-history (#243). The aggregation rules are
 * covered against the database in PurchaseAnalysisRepositoryTest; these tests cover the HTTP side:
 * the response shapes, the period validation, who may ask and whose data is visible.
 */
final class PurchaseAnalysisApiTest extends ApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    // ---- Overview ----

    #[DataProvider('managingRoles')]
    public function test_it_answers_the_overview_with_the_tree_the_products_and_the_buckets(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $alkohol = self::addCategory($organization, 'Alkohol', null);
        $whisky = self::addCategory($organization, 'Whisky', $alkohol);
        $jack = self::addProduct($organization, 'Jack Daniel\'s', $whisky, ProductMeasure::Volume, '0.5');
        $rent = self::addProduct($organization, 'Czynsz', null, ProductMeasure::None, null);
        self::addItem($organization, ['product' => $jack, 'issueDate' => '2026-11-05', 'sellerName' => 'Hurtownia A', 'quantity' => '6', 'packageSize' => '0.7', 'net' => 12_600, 'gross' => 15_498]);
        self::addItem($organization, ['product' => $jack, 'issueDate' => '2026-11-12', 'sellerName' => 'Hurtownia B', 'quantity' => '1', 'packageSize' => '1', 'net' => 3_300, 'gross' => 4_059]);
        self::addItem($organization, ['product' => $rent, 'issueDate' => '2026-11-02', 'quantity' => '1', 'net' => 650_000, 'gross' => 799_500]);
        self::addItem($organization, ['issueDate' => '2026-11-18', 'quantity' => '1', 'net' => 2_000, 'gross' => 2_460]);
        self::addItem($organization, ['issueDate' => '2026-11-19', 'quantity' => '1', 'net' => 500, 'gross' => 615, 'currency' => 'EUR']);

        $analysis = self::analysis($user, $organization, '2026-11-01', '2026-11-30')->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['2026-11-01', '2026-11-30', 'PLN'], [$analysis['from'], $analysis['to'], $analysis['currency']]);
        self::assertSame(['net' => 667_900, 'gross' => 821_517], $analysis['total']);
        self::assertSame([
            ['id' => $alkohol, 'name' => 'Alkohol', 'parentId' => null, 'own' => ['net' => 0, 'gross' => 0], 'total' => ['net' => 15_900, 'gross' => 19_557]],
            ['id' => $whisky, 'name' => 'Whisky', 'parentId' => $alkohol, 'own' => ['net' => 15_900, 'gross' => 19_557], 'total' => ['net' => 15_900, 'gross' => 19_557]],
        ], $analysis['categories']);
        // The products are by name. 4.2 L + 1 L = 5.2 L = 10.4 × 0.5 L; 15 900 ÷ 5.2 × 0.5 = 1 528.85 net, 19 557 ÷ 5.2 × 0.5 = 1 880.48 gross.
        self::assertSame([
            [
                'id' => $rent->getId()->toRfc4122(),
                'name' => 'Czynsz',
                'categoryId' => null,
                'measure' => 'none',
                'referenceQuantity' => null,
                'spend' => ['net' => 650_000, 'gross' => 799_500],
                'quantity' => null,
                'averageNetPrice' => null,
                'averageGrossPrice' => null,
                'lastPriceDate' => null,
                'lastNetPrice' => null,
                'lastGrossPrice' => null,
            ],
            [
                'id' => $jack->getId()->toRfc4122(),
                'name' => 'Jack Daniel\'s',
                'categoryId' => $whisky,
                'measure' => 'volume',
                'referenceQuantity' => '0.5',
                'spend' => ['net' => 15_900, 'gross' => 19_557],
                'quantity' => '10.4',
                'averageNetPrice' => 1_529,
                'averageGrossPrice' => 1_880,
                'lastPriceDate' => '2026-11-12',
                // 3 300 ÷ 1 L × 0.5 = 1 650 against 12 600 ÷ 4.2 × 0.5 = 1 500 of the other seller.
                'lastNetPrice' => ['price' => 1_650, 'change' => 150, 'changePercent' => '10'],
                'lastGrossPrice' => ['price' => 2_030, 'change' => 185, 'changePercent' => '10'],
            ],
        ], $analysis['products']);
        self::assertSame(['net' => 650_000, 'gross' => 799_500, 'count' => 1], $analysis['uncategorized']);
        self::assertSame(['net' => 2_000, 'gross' => 2_460, 'count' => 1], $analysis['notAssigned']);
        self::assertSame(['otherCurrency' => 1, 'withoutNet' => 0, 'withoutGross' => 0, 'withoutPackageSize' => 0], $analysis['leftOut']);
    }

    public function test_it_answers_an_empty_overview_for_an_organization_without_items(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::assertSame([
            'from' => '2026-11-01',
            'to' => '2026-11-30',
            'currency' => 'PLN',
            'total' => ['net' => 0, 'gross' => 0],
            'categories' => [],
            'products' => [],
            'uncategorized' => ['net' => 0, 'gross' => 0, 'count' => 0],
            'notAssigned' => ['net' => 0, 'gross' => 0, 'count' => 0],
            'leftOut' => ['otherCurrency' => 0, 'withoutNet' => 0, 'withoutGross' => 0, 'withoutPackageSize' => 0],
        ], self::analysis($owner, $organization, '2026-11-01', '2026-11-30')->toArray());
    }

    public function test_it_counts_only_the_invoices_issued_in_the_period(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addItem($organization, ['issueDate' => '2026-10-31', 'net' => 1_000, 'gross' => 1_230]);
        self::addItem($organization, ['issueDate' => '2026-11-01', 'net' => 20, 'gross' => 25]);
        self::addItem($organization, ['issueDate' => '2026-11-30', 'net' => 300, 'gross' => 369]);
        self::addItem($organization, ['issueDate' => '2026-12-01', 'net' => 4_000, 'gross' => 4_920]);

        $analysis = self::analysis($owner, $organization, '2026-11-01', '2026-11-30')->toArray();

        self::assertSame([['net' => 320, 'gross' => 394, 'count' => 2]], [$analysis['notAssigned']]);
    }

    public function test_it_accepts_a_period_of_a_year_of_366_days(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::analysis($owner, $organization, '2028-01-01', '2028-12-31');

        self::assertResponseStatusCodeSame(200);
    }

    /** @return iterable<string, array{array<string, string|list<string>>, string}> */
    public static function invalidPeriods(): iterable
    {
        yield 'no period' => [[], 'from'];
        yield 'no first date' => [['to' => '2026-11-30'], 'from'];
        yield 'no last date' => [['from' => '2026-11-01'], 'to'];
        yield 'a blank date' => [['from' => '', 'to' => '2026-11-30'], 'from'];
        yield 'a date that is not valid' => [['from' => '2026-02-30', 'to' => '2026-03-31'], 'from'];
        yield 'a date in another format' => [['from' => '01.11.2026', 'to' => '2026-11-30'], 'from'];
        yield 'dates that are lists' => [['from' => ['2026-11-01'], 'to' => '2026-11-30'], 'from'];
        yield 'the last date before the first' => [['from' => '2026-11-30', 'to' => '2026-11-01'], 'to'];
        yield 'more than 366 days' => [['from' => '2026-01-01', 'to' => '2027-01-02'], 'to'];
    }

    /** @param array<string, string|list<string>> $query */
    #[DataProvider('invalidPeriods')]
    public function test_it_answers_422_for_an_invalid_period(array $query, string $field): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($owner, $organization)->request('GET', '/api/purchase-analysis', ['query' => $query]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($field, array_column($response->toArray(false)['violations'], 'propertyPath'));
    }

    public function test_it_hides_another_organizations_items_from_the_overview(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $category = self::addCategory($elsewhere, 'Cudza', null);
        $foreign = self::addProduct($elsewhere, 'Fanta', $category, ProductMeasure::Volume, '1');
        self::addItem($elsewhere, ['product' => $foreign, 'quantity' => '2', 'packageSize' => '1', 'net' => 1_000, 'gross' => 1_230]);
        self::addItem($elsewhere, ['quantity' => '1', 'net' => 7_000, 'gross' => 8_610]);
        self::addItem($organization, ['quantity' => '1', 'net' => 300, 'gross' => 369]);
        // A member elsewhere too: the selected organization decides what is visible.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $mine = self::analysis($owner, $organization, '2026-11-01', '2026-11-30')->toArray();
        $theirs = self::analysis($ownerElsewhere, $elsewhere, '2026-11-01', '2026-11-30')->toArray();

        self::assertSame([], $mine['categories']);
        self::assertSame([], $mine['products']);
        self::assertSame(['net' => 300, 'gross' => 369], $mine['total']);
        self::assertSame(['net' => 300, 'gross' => 369, 'count' => 1], $mine['notAssigned']);
        self::assertSame(['Cudza'], array_column($theirs['categories'], 'name'));
        self::assertSame(['Fanta'], array_column($theirs['products'], 'name'));
        self::assertSame(['net' => 8_000, 'gross' => 9_840], $theirs['total']);
    }

    /** @return iterable<string, array{string}> */
    public static function requests(): iterable
    {
        yield 'overview' => ['/api/purchase-analysis?from=2026-11-01&to=2026-11-30'];
        yield 'price history' => ['/api/products/PRODUCT/price-history'];
    }

    #[DataProvider('requests')]
    public function test_it_refuses_the_analysis_to_employees_and_deactivated_members(string $path): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $product = self::addProduct($organization, 'Cola', null, ProductMeasure::Volume, '1');
        $path = str_replace('PRODUCT', $product->getId()->toRfc4122(), $path);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([$employee, $formerManager, $formerOwner] as $user) {
            static::clientFor($user, $organization)->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
        }
    }

    #[DataProvider('requests')]
    public function test_it_requires_a_signed_in_user_and_a_selected_organization(string $path): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);
        $path = str_replace('PRODUCT', Uuid::v7()->toRfc4122(), $path);

        static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']])->request('GET', $path);
        self::assertResponseStatusCodeSame(401);

        static::clientFor($owner)->request('GET', $path);
        self::assertResponseStatusCodeSame(400);
    }

    // ---- Price history ----

    #[DataProvider('managingRoles')]
    public function test_it_answers_the_price_points_with_their_sellers_and_invoices(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $jack = self::addProduct($organization, 'Jack Daniel\'s', null, ProductMeasure::Volume, '0.5');
        $invoiceId = self::addInvoice($organization, 'FV/1/2026');
        $first = self::addItem($organization, ['product' => $jack, 'invoiceId' => $invoiceId, 'issueDate' => '2026-10-20', 'sellerName' => 'Hurtownia A', 'name' => 'JACK DANIELS 0,7L', 'quantity' => '6', 'packageSize' => '0.7', 'net' => 12_600, 'gross' => 15_498]);
        $second = self::addItem($organization, ['product' => $jack, 'issueDate' => '2026-11-12', 'sellerName' => 'Hurtownia B', 'name' => 'Jack 1 l', 'sellerTaxId' => null, 'quantity' => '1', 'packageSize' => '1', 'net' => null, 'gross' => 4_059]);
        // Not price points: no package size, a correction, another currency.
        self::addItem($organization, ['product' => $jack, 'issueDate' => '2026-11-13', 'quantity' => '1', 'net' => 1_000, 'gross' => 1_230]);
        self::addItem($organization, ['product' => $jack, 'issueDate' => '2026-11-14', 'quantity' => '1', 'packageSize' => '1', 'net' => 1_000, 'gross' => 1_230, 'kind' => 'correction']);
        self::addItem($organization, ['product' => $jack, 'issueDate' => '2026-11-15', 'quantity' => '1', 'packageSize' => '1', 'net' => 1_000, 'gross' => 1_230, 'currency' => 'EUR']);

        $history = self::history($user, $organization, $jack->getId()->toRfc4122())->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame([
            'productId' => $jack->getId()->toRfc4122(),
            'productName' => 'Jack Daniel\'s',
            'measure' => 'volume',
            'referenceQuantity' => '0.5',
            'currency' => 'PLN',
            'from' => null,
            'to' => null,
            'points' => [
                [
                    'itemId' => $first,
                    'date' => '2026-10-20',
                    'sellerTaxId' => '5265877635',
                    'sellerName' => 'Hurtownia A',
                    'invoiceId' => $invoiceId,
                    'invoiceNumber' => 'FV/1/2026',
                    'itemName' => 'JACK DANIELS 0,7L',
                    'quantity' => '6',
                    'packageSize' => '0.7',
                    'bundleCount' => null,
                    'net' => ['price' => 1_500, 'change' => null, 'changePercent' => null],
                    'gross' => ['price' => 1_845, 'change' => null, 'changePercent' => null],
                ],
                [
                    'itemId' => $second,
                    'date' => '2026-11-12',
                    'sellerTaxId' => null,
                    'sellerName' => 'Hurtownia B',
                    'invoiceId' => $history['points'][1]['invoiceId'],
                    'invoiceNumber' => null,
                    'itemName' => 'Jack 1 l',
                    'quantity' => '1',
                    'packageSize' => '1',
                    'bundleCount' => null,
                    'net' => null,
                    'gross' => ['price' => 2_030, 'change' => 185, 'changePercent' => '10'],
                ],
            ],
        ], $history);
    }

    public function test_it_gives_a_bundle_and_the_same_goods_entered_as_a_total_the_same_prices(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $asTotal = self::addProduct($organization, 'Beer as a total', null, ProductMeasure::Volume, '0.5');
        $asBundle = self::addProduct($organization, 'Beer as a bundle', null, ProductMeasure::Volume, '0.5');
        // 3 crates of 20 × 0.5 L on one invoice and 2 trays of 24 × 0.33 L on another, entered both ways (ADR 0048 §3).
        $purchases = [
            ['issueDate' => '2026-11-05', 'quantity' => '3', 'total' => '10', 'single' => '0.5', 'count' => 20, 'net' => 9_000, 'gross' => 11_070],
            ['issueDate' => '2026-11-12', 'quantity' => '2', 'total' => '7.92', 'single' => '0.33', 'count' => 24, 'net' => 7_200, 'gross' => 8_856],
        ];
        foreach ($purchases as $purchase) {
            self::addItem($organization, ['product' => $asTotal, 'issueDate' => $purchase['issueDate'], 'quantity' => $purchase['quantity'], 'packageSize' => $purchase['total'], 'net' => $purchase['net'], 'gross' => $purchase['gross']]);
            self::addItem($organization, ['product' => $asBundle, 'issueDate' => $purchase['issueDate'], 'quantity' => $purchase['quantity'], 'packageSize' => $purchase['single'], 'bundleCount' => $purchase['count'], 'net' => $purchase['net'], 'gross' => $purchase['gross']]);
        }

        $overview = self::analysis($owner, $organization, '2026-11-01', '2026-11-30')->toArray();
        $byName = [];
        foreach ($overview['products'] as $product) {
            $byName[$product['name']] = array_diff_key($product, ['id' => true, 'name' => true]);
        }
        self::assertSame($byName['Beer as a total'], $byName['Beer as a bundle'], 'The overview: quantity, average, last price and change.');
        // 30 L + 15.84 L = 45.84 L = 91.68 × 0.5 L for 16 200 net.
        self::assertSame('91.68', $byName['Beer as a bundle']['quantity']);

        $totals = self::history($owner, $organization, $asTotal->getId()->toRfc4122())->toArray()['points'];
        $bundles = self::history($owner, $organization, $asBundle->getId()->toRfc4122())->toArray()['points'];
        self::assertCount(2, $bundles);
        foreach ($bundles as $index => $point) {
            self::assertSame($totals[$index]['net'], $point['net'], 'The price history.');
            self::assertSame($totals[$index]['gross'], $point['gross']);
            self::assertSame($purchases[$index]['single'], $point['packageSize']);
            self::assertSame($purchases[$index]['count'], $point['bundleCount']);
            self::assertNull($totals[$index]['bundleCount']);
        }
    }

    public function test_it_limits_the_history_to_a_period_and_changes_against_the_point_before_it(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s', null, ProductMeasure::Volume, '1');
        foreach (['2026-09-10' => 3_000, '2026-10-10' => 3_300, '2026-11-10' => 3_630] as $date => $net) {
            self::addItem($organization, ['product' => $jack, 'issueDate' => $date, 'quantity' => '1', 'packageSize' => '1', 'net' => $net, 'gross' => $net]);
        }

        $history = self::history($owner, $organization, $jack->getId()->toRfc4122(), ['from' => '2026-10-01', 'to' => '2026-10-31'])->toArray();

        self::assertSame(['2026-10-01', '2026-10-31'], [$history['from'], $history['to']]);
        self::assertSame(['2026-10-10'], array_column($history['points'], 'date'));
        self::assertSame(['price' => 3_300, 'change' => 300, 'changePercent' => '10'], $history['points'][0]['net']);
        self::assertSame(['2026-10-10', '2026-11-10'], array_column(self::history($owner, $organization, $jack->getId()->toRfc4122(), ['from' => '2026-10-01'])->toArray()['points'], 'date'));
        self::assertSame(['2026-09-10', '2026-10-10'], array_column(self::history($owner, $organization, $jack->getId()->toRfc4122(), ['to' => '2026-10-31'])->toArray()['points'], 'date'));
    }

    public function test_it_answers_no_points_for_a_product_without_a_unit_or_an_excluded_one(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $rent = self::addProduct($organization, 'Czynsz', null, ProductMeasure::None, null);
        $excluded = self::addProduct($organization, 'Kaucja', null, ProductMeasure::Volume, '1', excluded: true);
        foreach ([$rent, $excluded] as $product) {
            self::addItem($organization, ['product' => $product, 'quantity' => '1', 'packageSize' => '1', 'net' => 1_000, 'gross' => 1_230]);
        }

        $none = self::history($owner, $organization, $rent->getId()->toRfc4122())->toArray();
        $out = self::history($owner, $organization, $excluded->getId()->toRfc4122())->toArray();

        self::assertSame([[], 'none', null], [$none['points'], $none['measure'], $none['referenceQuantity']]);
        self::assertSame([[], 'volume'], [$out['points'], $out['measure']]);
    }

    public function test_it_answers_404_for_a_product_that_is_not_the_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreign = self::addProduct($elsewhere, 'Fanta', null, ProductMeasure::Volume, '1');
        self::addItem($elsewhere, ['product' => $foreign, 'quantity' => '1', 'packageSize' => '1', 'net' => 1_000, 'gross' => 1_230]);
        $unknown = Uuid::v7()->toRfc4122();

        $response = self::history($owner, $organization, $foreign->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        self::assertSame('purchasing.product_not_found', $response->toArray(false)['code']);

        $response = self::history($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['purchasing.product_not_found', ['productId' => $unknown]], [$response->toArray(false)['code'], $response->toArray(false)['parameters']]);
    }

    /** @return iterable<string, array{array<string, string|list<string>>, string}> */
    public static function invalidHistoryPeriods(): iterable
    {
        yield 'a date that is not valid' => [['from' => '2026-02-30'], 'from'];
        yield 'a date in another format' => [['to' => '30.11.2026'], 'to'];
        yield 'the last date before the first' => [['from' => '2026-11-30', 'to' => '2026-11-01'], 'to'];
    }

    /** @param array<string, string|list<string>> $query */
    #[DataProvider('invalidHistoryPeriods')]
    public function test_it_answers_422_for_an_invalid_history_period(array $query, string $field): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s', null, ProductMeasure::Volume, '1');

        $response = static::clientFor($owner, $organization)->request('GET', '/api/products/'.$jack->getId()->toRfc4122().'/price-history', ['query' => $query]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($field, array_column($response->toArray(false)['violations'], 'propertyPath'));
    }

    public function test_it_does_not_limit_the_length_of_the_history_period(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s', null, ProductMeasure::Volume, '1');

        self::history($owner, $organization, $jack->getId()->toRfc4122(), ['from' => '2020-01-01', 'to' => '2026-12-31']);

        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_refuses_an_employee_the_history_of_an_unknown_product_before_looking_it_up(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);

        self::history($employee, $organization, Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
    }

    // ---- Requests ----

    private static function analysis(User $user, Organization $organization, string $from, string $to): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/purchase-analysis', ['query' => ['from' => $from, 'to' => $to]]);
    }

    /** @param array<string, string> $query */
    private static function history(User $user, Organization $organization, string $productId, array $query = []): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/products/'.$productId.'/price-history', ['query' => $query]);
    }

    // ---- Arranging ----

    private static function addCategory(Organization $organization, string $name, ?string $parentId): string
    {
        $owner = OrganizationMother::ownerOf($organization);
        $id = Uuid::v7();

        self::inTenant($organization, static function () use ($id, $name, $parentId, $owner): void {
            $categories = static::getContainer()->get(ProductCategoryRepositoryInterface::class);
            $parent = null === $parentId ? null : $categories->findById(Uuid::fromString($parentId));
            $categories->add(ProductCategory::create($id, $name, $parent, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now()));
        });

        return $id->toRfc4122();
    }

    private static function addProduct(Organization $organization, string $name, ?string $categoryId, ProductMeasure $measure, ?string $referenceQuantity, bool $excluded = false): Product
    {
        $owner = OrganizationMother::ownerOf($organization);
        $product = null;

        self::inTenant($organization, static function () use ($name, $categoryId, $measure, $referenceQuantity, $excluded, $owner, &$product): void {
            $category = null === $categoryId ? null : static::getContainer()->get(ProductCategoryRepositoryInterface::class)->findById(Uuid::fromString($categoryId));
            $product = Product::create(Uuid::v7(), $name, $category, $measure, $referenceQuantity, $excluded, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());
            static::getContainer()->get(ProductRepositoryInterface::class)->add($product);
        });

        return $product ?? throw new \LogicException('Product expected.');
    }

    /** @return string the invoice's id */
    private static function addInvoice(Organization $organization, string $number): string
    {
        $invoice = PurchaseInvoiceMother::imported(invoiceNumber: $number);
        self::inTenant($organization, static function () use ($invoice): void {
            static::getContainer()->get(PurchaseInvoiceRepositoryInterface::class)->add($invoice);
        });

        return $invoice->getId()->toRfc4122();
    }

    /**
     * Records an item in the organization, linked to the product when it has one.
     *
     * @param array{product?: Product, invoiceId?: string, issueDate?: string, kind?: string, currency?: string, sellerTaxId?: ?string, sellerName?: string, name?: string, quantity?: string, packageSize?: string, bundleCount?: int, net?: ?int, gross?: ?int} $overrides
     *
     * @return string the item's id
     */
    private static function addItem(Organization $organization, array $overrides): string
    {
        $id = Uuid::v7();
        self::inTenant($organization, static function () use ($id, $overrides): void {
            $item = PurchasedItem::record($id, new PurchasedItemDetails(
                Uuid::fromString($overrides['invoiceId'] ?? Uuid::v7()->toRfc4122()), 1, BusinessDate::fromString($overrides['issueDate'] ?? '2026-11-10'),
                $overrides['kind'] ?? 'regular', $overrides['currency'] ?? 'PLN',
                \array_key_exists('sellerTaxId', $overrides) ? $overrides['sellerTaxId'] : '5265877635', $overrides['sellerName'] ?? 'Hurtownia Napojów',
                1, $overrides['name'] ?? 'Item', null, null, 'szt.', $overrides['quantity'] ?? '1', null, null, null,
                \array_key_exists('net', $overrides) ? $overrides['net'] : 1_000, \array_key_exists('gross', $overrides) ? $overrides['gross'] : 1_230, null, null, false,
            ), Clock::get()->now());
            static::getContainer()->get(PurchasedItemRepositoryInterface::class)->addAll([$item]);
        });

        if (isset($overrides['product'])) {
            static::getContainer()->get(Connection::class)->executeStatement('UPDATE purchased_item SET product_id = :product, status = :status, package_size = :size, bundle_count = :bundle WHERE id = :id', [
                'bundle' => $overrides['bundleCount'] ?? null,
                'product' => $overrides['product']->getId()->toRfc4122(),
                'status' => 'confirmed',
                'size' => $overrides['packageSize'] ?? null,
                'id' => $id->toRfc4122(),
            ]);
        }

        return $id->toRfc4122();
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private static function inTenant(Organization $organization, \Closure $action): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $action();
        } finally {
            $tenantContext->reset();
            // A request starts with an empty identity map: an arranged entity must not be found by
            // id there without the TenantFilter (EntityManager::find() reads the map first).
            $entityManager->clear();
        }
    }
}
