<?php

declare(strict_types=1);

namespace App\Tests\Api\Purchasing;

use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\AssignPurchasedItems\AssignPurchasedItemsCommand;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
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
 * GET /purchased-items, GET and PATCH /purchased-items/{id} and POST /purchased-items/assign (#242).
 * Organizations arranged by the factories start without items or products.
 */
final class PurchasedItemApiTest extends ApiTestCase
{
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    // ---- Listing ----

    #[DataProvider('managingRoles')]
    public function test_it_lists_the_items_with_their_invoice_seller_and_product(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $invoiceId = self::addInvoice($organization, 'FV/1/2026');
        $linked = self::addItem($organization, ['invoiceId' => $invoiceId, 'name' => 'JACK DANIELS 0,7L', 'product' => $jack, 'status' => 'confirmed', 'packageSize' => '0.7']);
        self::addItem($organization, ['invoiceId' => Uuid::v7()->toRfc4122(), 'name' => 'Receipt item', 'issueDate' => '2026-10-01']);

        $page = self::list($user, $organization)->toArray();

        self::assertSame([2, 1, 30], [$page['total'], $page['page'], $page['itemsPerPage']]);
        self::assertSame(['JACK DANIELS 0,7L', 'Receipt item'], array_column($page['items'], 'name'));
        self::assertSame([
            'id' => $linked,
            'invoiceId' => $invoiceId,
            'invoiceNumber' => 'FV/1/2026',
            'invoiceIssueDate' => '2026-11-10',
            'invoiceKind' => 'regular',
            'currency' => 'PLN',
            'sellerTaxId' => '5265877635',
            'sellerName' => 'Hurtownia Napojów',
            'position' => 1,
            'name' => 'JACK DANIELS 0,7L',
            'sellerCode' => null,
            'gtin' => null,
            'unit' => 'szt.',
            'quantity' => '6',
            'unitNetPrice' => '10',
            'unitGrossPrice' => '12.3',
            'discount' => null,
            'netAmount' => 1_000,
            'grossAmount' => 1_230,
            'vatAmount' => 230,
            'vatRate' => '23',
            'beforeCorrection' => false,
            'productId' => $jack->getId()->toRfc4122(),
            'productName' => 'Jack Daniel\'s',
            'status' => 'confirmed',
            'packageSize' => '0.7',
        ], $page['items'][0]);
        self::assertNull($page['items'][1]['invoiceNumber']);
        self::assertSame([null, null, 'unassigned', null], [$page['items'][1]['productId'], $page['items'][1]['productName'], $page['items'][1]['status'], $page['items'][1]['packageSize']]);
    }

    public function test_it_lists_an_empty_page_for_an_organization_without_items(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::assertSame(['items' => [], 'total' => 0, 'page' => 1, 'itemsPerPage' => 30], self::list($owner, $organization)->toArray());
    }

    public function test_it_pages_newest_issue_date_first(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        foreach (range(1, 5) as $day) {
            self::addItem($organization, ['name' => 'Item '.$day, 'issueDate' => \sprintf('2026-11-%02d', $day)]);
        }

        $first = self::list($owner, $organization, ['itemsPerPage' => '2'])->toArray();
        $second = self::list($owner, $organization, ['itemsPerPage' => '2', 'page' => '2'])->toArray();
        $last = self::list($owner, $organization, ['itemsPerPage' => '2', 'page' => '3'])->toArray();
        $beyond = self::list($owner, $organization, ['itemsPerPage' => '2', 'page' => '4'])->toArray();

        self::assertSame(['Item 5', 'Item 4'], array_column($first['items'], 'name'));
        self::assertSame(['Item 3', 'Item 2'], array_column($second['items'], 'name'));
        self::assertSame(['Item 1'], array_column($last['items'], 'name'));
        self::assertSame([], $beyond['items']);
        self::assertSame([5, 5, 5, 5], [$first['total'], $second['total'], $last['total'], $beyond['total']]);
        self::assertSame([1, 2, 3, 4, 2], [$first['page'], $second['page'], $last['page'], $beyond['page'], $first['itemsPerPage']]);
    }

    public function test_it_filters_the_items(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $cola = self::addProduct($organization, 'Cola');
        self::addItem($organization, ['name' => 'Unknown thing', 'issueDate' => '2026-11-01']);
        self::addItem($organization, ['name' => 'JACK DANIELS 0,7L', 'issueDate' => '2026-11-05', 'product' => $jack, 'status' => 'suggested']);
        self::addItem($organization, ['name' => 'Whiskey Jack Daniel\'s 700ml', 'issueDate' => '2026-11-10', 'product' => $jack, 'status' => 'assigned']);
        self::addItem($organization, ['name' => 'Cola 1l', 'issueDate' => '2026-11-20', 'product' => $cola, 'status' => 'confirmed']);

        $names = static fn (array $query): array => array_column(self::list($owner, $organization, $query)->toArray()['items'], 'name');

        self::assertSame(['JACK DANIELS 0,7L', 'Unknown thing'], $names(['status' => 'unassigned,suggested']));
        self::assertSame(['Cola 1l'], $names(['status' => 'confirmed']));
        self::assertSame(['Whiskey Jack Daniel\'s 700ml', 'JACK DANIELS 0,7L'], $names(['productId' => $jack->getId()->toRfc4122()]));
        self::assertSame(['Whiskey Jack Daniel\'s 700ml'], $names(['productId' => $jack->getId()->toRfc4122(), 'status' => 'assigned']));
        self::assertSame(['Cola 1l', 'Whiskey Jack Daniel\'s 700ml'], $names(['from' => '2026-11-10']));
        self::assertSame(['Whiskey Jack Daniel\'s 700ml', 'JACK DANIELS 0,7L'], $names(['from' => '2026-11-05', 'to' => '2026-11-10']));
        self::assertSame(['JACK DANIELS 0,7L', 'Unknown thing'], $names(['to' => '2026-11-09']));
        self::assertSame(['Unknown thing'], $names(['to' => '2026-11-04']));
        self::assertSame(['Whiskey Jack Daniel\'s 700ml', 'JACK DANIELS 0,7L'], $names(['search' => 'jack dan']));
        self::assertSame(['JACK DANIELS 0,7L'], $names(['search' => 'jack', 'status' => 'suggested']));
        self::assertSame([], $names(['search' => 'jack', 'productId' => $cola->getId()->toRfc4122()]));
        self::assertSame([], $names(['productId' => Uuid::v7()->toRfc4122()]));
        self::assertCount(4, $names(['status' => '', 'search' => '']), 'Empty filters filter nothing.');
        self::assertSame(2, self::list($owner, $organization, ['productId' => $jack->getId()->toRfc4122(), 'itemsPerPage' => '1'])->toArray()['total']);
    }

    public function test_it_filters_by_several_statuses_given_as_a_repeated_array_parameter(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        self::addItem($organization, ['name' => 'A', 'issueDate' => '2026-11-01']);
        self::addItem($organization, ['name' => 'B', 'issueDate' => '2026-11-02', 'product' => $jack, 'status' => 'suggested']);
        self::addItem($organization, ['name' => 'C', 'issueDate' => '2026-11-03', 'product' => $jack, 'status' => 'confirmed']);

        $response = static::clientFor($owner, $organization)->request('GET', '/api/purchased-items?status[]=unassigned&status[]=suggested');

        self::assertResponseIsSuccessful();
        self::assertSame(['B', 'A'], array_column($response->toArray()['items'], 'name'));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidFilters(): iterable
    {
        yield 'unknown status' => [['status' => 'finished']];
        yield 'one unknown status of two' => [['status' => 'unassigned,finished']];
        yield 'product id that is not a UUID' => [['productId' => 'jack']];
        yield 'date that is not a date' => [['from' => 'yesterday']];
        yield 'impossible date' => [['to' => '2026-02-30']];
        yield 'to before from' => [['from' => '2026-11-10', 'to' => '2026-11-09']];
        yield 'page zero' => [['page' => '0']];
        yield 'page that is not a number' => [['page' => 'two']];
        yield 'negative page' => [['page' => '-1']];
        yield 'page size zero' => [['itemsPerPage' => '0']];
        yield 'page size over the limit' => [['itemsPerPage' => '101']];
        yield 'page size that is not a number' => [['itemsPerPage' => 'many']];
        yield 'long search' => [['search' => 'x[long]']];
    }

    /** @param array<string, string> $query */
    #[DataProvider('invalidFilters')]
    public function test_it_answers_422_for_an_invalid_filter(array $query): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $query = array_map(static fn (string $value): string => 'x[long]' === $value ? str_repeat('x', 513) : $value, $query);

        self::list($owner, $organization, $query);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_accepts_the_largest_page_size(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::list($owner, $organization, ['itemsPerPage' => '100']);

        self::assertResponseIsSuccessful();
    }

    // ---- One item ----

    public function test_it_shows_an_item(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $invoiceId = self::addInvoice($organization, 'FV/7/2026');
        $id = self::addItem($organization, ['invoiceId' => $invoiceId, 'name' => 'Cola 1l']);

        $item = self::get($owner, $organization, $id)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame([$id, 'FV/7/2026', 'Cola 1l', 'unassigned'], [$item['id'], $item['invoiceNumber'], $item['name'], $item['status']]);
        self::assertSame(self::list($owner, $organization)->toArray()['items'][0], $item);
    }

    public function test_it_answers_404_for_an_unknown_item(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::get($owner, $organization, Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        $response = self::get($owner, $organization, 'not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        self::assertArrayHasKey('detail', $response->toArray(false));
    }

    // ---- Changing one item ----

    #[DataProvider('managingRoles')]
    public function test_it_links_an_item_to_a_product_and_confirms_it(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $id = self::addItem($organization, ['name' => 'JACK DANIELS 0,7L']);

        $item = self::change($user, $organization, $id, ['productId' => $jack->getId()->toRfc4122(), 'packageSize' => '0.70'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['confirmed', $jack->getId()->toRfc4122(), 'Jack Daniel\'s', '0.7'], [$item['status'], $item['productId'], $item['productName'], $item['packageSize']]);
        self::assertSame($item, self::get($user, $organization, $id)->toArray());
    }

    public function test_it_takes_an_item_off_its_product_back_to_unassigned_and_keeps_its_package_size(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $id = self::addItem($organization, ['product' => $jack, 'status' => 'confirmed', 'packageSize' => '0.7']);

        $item = self::change($owner, $organization, $id, ['productId' => null])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['unassigned', null, null, '0.7'], [$item['status'], $item['productId'], $item['productName'], $item['packageSize']]);
    }

    public function test_it_sets_and_clears_the_package_size_alone_and_confirms_the_link(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $id = self::addItem($organization, ['product' => $jack, 'status' => 'assigned']);

        $set = self::change($owner, $organization, $id, ['packageSize' => '1'])->toArray();
        self::assertSame(['confirmed', $jack->getId()->toRfc4122(), '1'], [$set['status'], $set['productId'], $set['packageSize']]);

        $cleared = self::change($owner, $organization, $id, ['packageSize' => null])->toArray();
        self::assertSame(['confirmed', $jack->getId()->toRfc4122(), null], [$cleared['status'], $cleared['productId'], $cleared['packageSize']]);
    }

    public function test_it_confirms_a_suggestion_with_an_empty_patch_and_keeps_an_unassigned_item_unassigned(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $suggested = self::addItem($organization, ['product' => $jack, 'status' => 'suggested', 'packageSize' => '0.7']);
        $unassigned = self::addItem($organization, []);

        $confirmed = self::change($owner, $organization, $suggested, [])->toArray();
        $same = self::change($owner, $organization, $unassigned, [])->toArray();

        self::assertSame(['confirmed', $jack->getId()->toRfc4122(), '0.7'], [$confirmed['status'], $confirmed['productId'], $confirmed['packageSize']]);
        self::assertSame(['unassigned', null, null], [$same['status'], $same['productId'], $same['packageSize']]);
    }

    public function test_it_moves_an_item_to_another_product(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $cola = self::addProduct($organization, 'Cola');
        $id = self::addItem($organization, ['product' => $jack, 'status' => 'confirmed', 'packageSize' => '0.7']);

        $item = self::change($owner, $organization, $id, ['productId' => $cola->getId()->toRfc4122(), 'packageSize' => '1'])->toArray();

        self::assertSame(['Cola', 'confirmed', '1'], [$item['productName'], $item['status'], $item['packageSize']]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidChanges(): iterable
    {
        yield 'zero' => [['packageSize' => '0']];
        yield 'negative' => [['packageSize' => '-1']];
        yield 'text' => [['packageSize' => 'big']];
        yield 'comma' => [['packageSize' => '0,7']];
        yield 'seven decimals' => [['packageSize' => '0.0000001']];
        yield 'seventeen integer digits' => [['packageSize' => '10000000000000000']];
        yield 'a number instead of text' => [['packageSize' => 0.7]];
        yield 'product that is not a UUID' => [['productId' => 'jack']];
        yield 'product of the wrong type' => [['productId' => 5]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidChanges')]
    public function test_it_answers_422_for_an_invalid_change_and_changes_nothing(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $id = self::addItem($organization, ['product' => $jack, 'status' => 'suggested', 'packageSize' => '0.7']);

        self::change($owner, $organization, $id, $body);

        self::assertResponseStatusCodeSame(422);
        $item = self::get($owner, $organization, $id)->toArray();
        self::assertSame(['suggested', '0.7'], [$item['status'], $item['packageSize']]);
    }

    public function test_it_answers_404_for_changing_an_unknown_item_or_linking_to_an_unknown_product(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addItem($organization, []);

        $response = self::change($owner, $organization, Uuid::v7()->toRfc4122(), ['packageSize' => '1']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('purchasing.purchased_item_not_found', $response->toArray(false)['code']);

        $response = self::change($owner, $organization, $id, ['productId' => Uuid::v7()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('purchasing.purchased_item_product_not_found', $response->toArray(false)['code']);
        self::assertSame('unassigned', self::get($owner, $organization, $id)->toArray()['status']);
    }

    // ---- Assigning in bulk ----

    #[DataProvider('managingRoles')]
    public function test_it_assigns_several_items_to_a_product_in_one_request(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $first = self::addItem($organization, ['name' => 'First']);
        $second = self::addItem($organization, ['name' => 'Second', 'status' => 'suggested', 'product' => self::addProduct($organization, 'Else')]);
        $untouched = self::addItem($organization, ['name' => 'Third']);

        $response = self::assign($user, $organization, ['itemIds' => [$first, $second], 'productId' => $jack->getId()->toRfc4122(), 'packageSize' => '0.70']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        foreach ([$first, $second] as $id) {
            $item = self::get($user, $organization, $id)->toArray();
            self::assertSame(['confirmed', 'Jack Daniel\'s', '0.7'], [$item['status'], $item['productName'], $item['packageSize']], $id);
        }
        $item = self::get($user, $organization, $untouched)->toArray();
        self::assertSame(['unassigned', null, null], [$item['status'], $item['productName'], $item['packageSize']]);
    }

    public function test_it_lets_each_item_keep_its_package_size_when_assigning_without_one(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $sized = self::addItem($organization, ['packageSize' => '0.7']);
        $unsized = self::addItem($organization, []);

        self::assign($owner, $organization, ['itemIds' => [$sized, $unsized], 'productId' => $jack->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(204);
        self::assign($owner, $organization, ['itemIds' => [$sized], 'productId' => $jack->getId()->toRfc4122(), 'packageSize' => null]);
        self::assertResponseStatusCodeSame(204);

        $sizedItem = self::get($owner, $organization, $sized)->toArray();
        $unsizedItem = self::get($owner, $organization, $unsized)->toArray();
        self::assertSame(['0.7', null], [$sizedItem['packageSize'], $unsizedItem['packageSize']]);
        self::assertSame(['confirmed', 'confirmed'], [$sizedItem['status'], $unsizedItem['status']]);
    }

    public function test_it_answers_404_and_changes_nothing_when_an_item_or_the_product_is_another_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $foreignProduct = self::addProduct($elsewhere, 'Fanta');
        $mine = self::addItem($organization, ['name' => 'Mine']);
        $foreignItem = self::addItem($elsewhere, ['name' => 'Theirs']);

        $response = self::assign($owner, $organization, ['itemIds' => [$mine, $foreignItem], 'productId' => $jack->getId()->toRfc4122(), 'packageSize' => '1']);
        self::assertResponseStatusCodeSame(404);
        $problem = $response->toArray(false);
        self::assertSame(['purchasing.purchased_item_not_found', ['itemId' => $foreignItem]], [$problem['code'], $problem['parameters']]);

        $response = self::assign($owner, $organization, ['itemIds' => [$mine], 'productId' => $foreignProduct->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('purchasing.purchased_item_product_not_found', $response->toArray(false)['code']);

        self::assign($owner, $organization, ['itemIds' => [$mine, Uuid::v7()->toRfc4122()], 'productId' => $jack->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(['unassigned', null], [self::itemRow($mine)['status'], self::itemRow($mine)['product_id']]);
        self::assertSame(['unassigned', null], [self::itemRow($foreignItem)['status'], self::itemRow($foreignItem)['product_id']]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidAssignments(): iterable
    {
        // ITEM and PRODUCT stand for an item and a product of the organization.
        yield 'no items' => [['productId' => 'PRODUCT']];
        yield 'empty items' => [['itemIds' => [], 'productId' => 'PRODUCT']];
        yield 'items that are not a list' => [['itemIds' => 'ITEM', 'productId' => 'PRODUCT']];
        yield 'an item that is not a UUID' => [['itemIds' => ['one'], 'productId' => 'PRODUCT']];
        yield 'a blank item' => [['itemIds' => [''], 'productId' => 'PRODUCT']];
        yield 'an item listed twice' => [['itemIds' => ['ITEM', 'ITEM'], 'productId' => 'PRODUCT']];
        yield 'too many items' => [['itemIds' => 'TOO_MANY', 'productId' => 'PRODUCT']];
        yield 'no product' => [['itemIds' => ['ITEM']]];
        yield 'null product' => [['itemIds' => ['ITEM'], 'productId' => null]];
        yield 'product that is not a UUID' => [['itemIds' => ['ITEM'], 'productId' => 'jack']];
        yield 'package size zero' => [['itemIds' => ['ITEM'], 'productId' => 'PRODUCT', 'packageSize' => '0']];
        yield 'negative package size' => [['itemIds' => ['ITEM'], 'productId' => 'PRODUCT', 'packageSize' => '-0.5']];
        yield 'package size that is not a number' => [['itemIds' => ['ITEM'], 'productId' => 'PRODUCT', 'packageSize' => 'x']];
        yield 'package size with too many decimals' => [['itemIds' => ['ITEM'], 'productId' => 'PRODUCT', 'packageSize' => '0.1234567']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidAssignments')]
    public function test_it_answers_422_for_an_invalid_assignment_and_changes_nothing(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $id = self::addItem($organization, []);
        $replacements = ['ITEM' => $id, 'PRODUCT' => $jack->getId()->toRfc4122()];
        $body = array_map(static fn (mixed $value): mixed => match (true) {
            'TOO_MANY' === $value => array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(0, AssignPurchasedItemsCommand::MAX_ITEMS)),
            \is_array($value) => array_map(static fn (mixed $entry): mixed => \is_string($entry) ? ($replacements[$entry] ?? $entry) : $entry, $value),
            \is_string($value) => $replacements[$value] ?? $value,
            default => $value,
        }, $body);

        self::assign($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('unassigned', self::itemRow($id)['status']);
    }

    public function test_it_assigns_as_many_items_as_the_limit_allows(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $ids = [];
        foreach (range(1, 20) as $index) {
            $ids[] = self::addItem($organization, ['name' => 'Item '.$index]);
        }

        self::assign($owner, $organization, ['itemIds' => $ids, 'productId' => $jack->getId()->toRfc4122(), 'packageSize' => '1']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(20, (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM purchased_item WHERE status = 'confirmed'"));
    }

    // ---- Who may, and whose items ----

    public function test_it_hides_another_organizations_items(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreignProduct = self::addProduct($elsewhere, 'Fanta');
        $mine = self::addItem($organization, ['name' => 'Mine']);
        $foreign = self::addItem($elsewhere, ['name' => 'Theirs', 'product' => $foreignProduct, 'status' => 'confirmed', 'packageSize' => '1']);
        // A member elsewhere too, as an employee: the selected organization decides what is visible.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::list($ownerElsewhere, $elsewhere)->toArray();

        $page = self::list($owner, $organization)->toArray();
        self::assertSame(['Mine'], array_column($page['items'], 'name'));
        self::assertSame(1, $page['total']);
        self::assertSame([], self::list($owner, $organization, ['search' => 'theirs'])->toArray()['items']);
        self::assertSame([], self::list($owner, $organization, ['productId' => $foreignProduct->getId()->toRfc4122()])->toArray()['items']);
        self::get($owner, $organization, $foreign);
        self::assertResponseStatusCodeSame(404);
        self::change($owner, $organization, $foreign, ['productId' => null]);
        self::assertResponseStatusCodeSame(404);
        self::change($owner, $organization, $mine, ['productId' => $foreignProduct->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, self::list($ownerElsewhere, $elsewhere)->toArray());
        self::assertSame('confirmed', self::itemRow($foreign)['status']);
    }

    /** @return iterable<string, array{\Closure(User, Organization, string, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET collection' => [static fn (User $user, Organization $organization, string $item, string $product): ResponseInterface => self::list($user, $organization)];
        yield 'GET item' => [static fn (User $user, Organization $organization, string $item, string $product): ResponseInterface => self::get($user, $organization, $item)];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $item, string $product): ResponseInterface => self::change($user, $organization, $item, ['productId' => $product])];
        yield 'assign' => [static fn (User $user, Organization $organization, string $item, string $product): ResponseInterface => self::assign($user, $organization, ['itemIds' => [$item], 'productId' => $product])];
    }

    /** @param \Closure(User, Organization, string, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_purchased_items_to_employees_and_deactivated_members(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $product = self::addProduct($organization, 'Cola');
        $item = self::addItem($organization, []);
        $before = self::list($owner, $organization)->toArray();

        foreach ([$employee, $formerManager, $formerOwner] as $user) {
            $request($user, $organization, $item, $product->getId()->toRfc4122());
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::list($owner, $organization)->toArray());
    }

    public function test_it_refuses_an_employee_before_looking_at_the_item(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);

        self::get($employee, $organization, Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        self::change($employee, $organization, Uuid::v7()->toRfc4122(), ['productId' => null]);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_requires_a_signed_in_user_and_a_selected_organization(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']])->request('GET', '/api/purchased-items');
        self::assertResponseStatusCodeSame(401);

        static::clientFor($owner)->request('GET', '/api/purchased-items');
        self::assertResponseStatusCodeSame(400);
    }

    // ---- Requests ----

    /** @param array<string, string> $query */
    private static function list(User $user, Organization $organization, array $query = []): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/purchased-items', ['query' => $query]);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/purchased-items/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function change(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/purchased-items/'.$id, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    /** @param array<string, mixed> $body */
    private static function assign(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/purchased-items/assign', ['json' => $body]);
    }

    // ---- Arranging ----

    private static function addProduct(Organization $organization, string $name): Product
    {
        $owner = OrganizationMother::ownerOf($organization);
        $product = null;

        self::inTenant($organization, static function () use ($name, $owner, &$product): void {
            $product = Product::create(Uuid::v7(), $name, null, ProductMeasure::Volume, null, false, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());
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
     * Records an item in the organization, linked the way matching (#233) or a person would have
     * linked it: its product, status and package size.
     *
     * @param array{invoiceId?: string, name?: string, issueDate?: string, product?: Product, status?: string, packageSize?: string} $overrides
     *
     * @return string the item's id
     */
    private static function addItem(Organization $organization, array $overrides): string
    {
        $id = Uuid::v7();
        self::inTenant($organization, static function () use ($id, $overrides): void {
            $item = PurchasedItem::record($id, new PurchasedItemDetails(
                Uuid::fromString($overrides['invoiceId'] ?? Uuid::v7()->toRfc4122()), 1, BusinessDate::fromString($overrides['issueDate'] ?? '2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
                1, $overrides['name'] ?? 'Item', null, null, 'szt.', '6', '10', '12.3', null, 1_000, 1_230, 230, '23', false,
            ), Clock::get()->now());
            static::getContainer()->get(PurchasedItemRepositoryInterface::class)->addAll([$item]);
        });

        if (isset($overrides['product']) || isset($overrides['packageSize']) || isset($overrides['status'])) {
            static::getContainer()->get(Connection::class)->executeStatement('UPDATE purchased_item SET product_id = :product, status = :status, package_size = :size WHERE id = :id', [
                'product' => isset($overrides['product']) ? $overrides['product']->getId()->toRfc4122() : null,
                'status' => $overrides['status'] ?? 'unassigned',
                'size' => $overrides['packageSize'] ?? null,
                'id' => $id->toRfc4122(),
            ]);
        }

        return $id->toRfc4122();
    }

    /** @return array{status: string, product_id: ?string, package_size: ?string} */
    private static function itemRow(string $id): array
    {
        /** @var array{status: string, product_id: ?string, package_size: ?string} */
        return static::getContainer()->get(Connection::class)->fetchAssociative('SELECT status, product_id, package_size FROM purchased_item WHERE id = :id', ['id' => $id]);
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
