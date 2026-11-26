<?php

declare(strict_types=1);

namespace App\Tests\Api\Purchasing;

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
use App\User\Domain\Model\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /product-proposals and the owner's decisions on them: accept, assign and dismiss (ADR 0044). The
 * model's proposals are arranged on the items directly; nothing here reaches the model.
 */
final class ProductProposalApiTest extends ApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lists_the_proposals_grouped_by_name_with_counts_and_examples(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $whisky = self::addCategory($organization, 'Whisky');
        self::addItem($organization, 'JACK DANIELS 0,7L 40%', 'Jack Daniel\'s', $whisky);
        self::addItem($organization, 'Whiskey Jack Daniel\'s Old No.7 700ml', 'Jack Daniels', $whisky);
        self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie', null, ProductMeasure::Volume);
        self::addItem($organization, 'Cola 1l', null);

        $proposals = self::list($user, $organization)->toArray();

        self::assertSame([
            [
                'id' => 'jack-daniels',
                'name' => 'Jack Daniel\'s',
                'measure' => 'volume',
                'categoryId' => $whisky,
                'itemCount' => 2,
                'exampleNames' => ['JACK DANIELS 0,7L 40%', 'Whiskey Jack Daniel\'s Old No.7 700ml'],
                'existingProductId' => null,
            ],
            ['id' => 'tyskie', 'name' => 'Tyskie', 'measure' => 'volume', 'categoryId' => null, 'itemCount' => 1, 'exampleNames' => ['TYSKIE GOLD 0,5L'], 'existingProductId' => null],
        ], $proposals);
    }

    public function test_it_lists_nothing_for_an_organization_without_proposals(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::assertSame([], self::list($owner, $organization)->toArray());
    }

    public function test_it_names_the_product_a_proposal_equals_by_now(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');
        $product = self::addProduct($organization, 'TYSKIE');

        self::assertSame($product->getId()->toRfc4122(), self::list($owner, $organization)->toArray()[0]['existingProductId']);
    }

    #[DataProvider('managingRoles')]
    public function test_it_creates_the_product_and_confirms_the_items_and_queues_matching(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $whisky = self::addCategory($organization, 'Whisky');
        $a = self::addItem($organization, 'JACK DANIELS 0,7L 40%', 'Jack Daniel\'s', $whisky);
        $b = self::addItem($organization, 'J.DANIELS 1L', 'Jack Daniels', $whisky);
        $other = self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');

        self::accept($user, $organization, 'jack-daniels', ['name' => 'Jack Daniel\'s', 'measure' => 'volume', 'categoryId' => $whisky]);

        self::assertResponseStatusCodeSame(204);
        $products = self::productRows($organization);
        self::assertCount(1, $products);
        self::assertSame(['Jack Daniel\'s', 'volume', $whisky, '1.000000'], [$products[0]['name'], $products[0]['measure'], $products[0]['category_id'], $products[0]['reference_quantity']]);
        self::assertSame(['confirmed', $products[0]['id'], '0.700000'], array_values(self::itemRow($a)));
        self::assertSame(['confirmed', $products[0]['id'], '1.000000'], array_values(self::itemRow($b)));
        self::assertSame('unassigned', self::itemRow($other)['status']);
        // The aliases were queued by the confirmation, the model run for the new catalog (now, and for the unassigned items once the burst settles) by the product.
        $keys = array_map(static fn (Envelope $envelope): string => (string) $envelope->last(DeduplicateStamp::class)?->getKey(), self::queuedJobs());
        $org = $organization->getId()->toRfc4122();
        sort($keys);
        self::assertSame(['purchasing-match-items:'.$org.':after-catalog-change', 'purchasing-match-items:'.$org.':all_matchable:aliases', 'purchasing-match-items:'.$org.':not_yet_matched:model'], $keys);
        self::assertSame(['tyskie'], array_column(self::list($user, $organization)->toArray(), 'id'), 'The decided proposal is gone from the list.');
        // And the aliases are learned, from the items' own names.
        self::assertGreaterThanOrEqual(2, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM product_alias WHERE organization_id = :id', ['id' => $org]));
    }

    public function test_it_creates_the_product_with_the_edited_name_measure_category_and_reference_quantity(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $beer = self::addCategory($organization, 'Beer');
        self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');

        self::accept($owner, $organization, 'tyskie', ['name' => ' Tyskie Gold ', 'measure' => 'volume', 'categoryId' => $beer, 'referenceQuantity' => '0.5']);

        self::assertResponseStatusCodeSame(204);
        $product = self::productRows($organization)[0];
        self::assertSame(['Tyskie Gold', $beer, '0.500000'], [$product['name'], $product['category_id'], $product['reference_quantity']]);
    }

    public function test_it_refuses_a_name_a_product_has_and_an_unknown_or_foreign_category_and_changes_nothing(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreign = self::addCategory($elsewhere, 'Whisky');
        $item = self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');
        self::addProduct($organization, 'Cola');

        $taken = self::accept($owner, $organization, 'tyskie', ['name' => 'COLA', 'measure' => 'volume']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('purchasing.product_name_taken', $taken->toArray(false)['code']);
        foreach ([Uuid::v7()->toRfc4122(), $foreign] as $categoryId) {
            $unknown = self::accept($owner, $organization, 'tyskie', ['name' => 'Tyskie', 'measure' => 'volume', 'categoryId' => $categoryId]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('purchasing.unknown_product_category', $unknown->toArray(false)['code']);
        }

        self::assertCount(1, self::productRows($organization));
        self::assertSame('unassigned', self::itemRow($item)['status']);
        self::assertSame([], self::queuedJobs());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidAcceptances(): iterable
    {
        yield 'no name' => [['measure' => 'volume']];
        yield 'a blank name' => [['name' => '  ', 'measure' => 'volume']];
        yield 'a name that is too long' => [['name' => str_repeat('a', 129), 'measure' => 'volume']];
        yield 'no measure' => [['name' => 'Tyskie']];
        yield 'an unknown measure' => [['name' => 'Tyskie', 'measure' => 'weight']];
        yield 'a category that is not a UUID' => [['name' => 'Tyskie', 'measure' => 'volume', 'categoryId' => 'beer']];
        yield 'a reference quantity that is not positive' => [['name' => 'Tyskie', 'measure' => 'volume', 'referenceQuantity' => '0']];
        yield 'a reference quantity for a product with no unit' => [['name' => 'Czynsz', 'measure' => 'none', 'referenceQuantity' => '1']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidAcceptances')]
    public function test_it_answers_422_for_an_invalid_product_and_changes_nothing(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $item = self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');

        self::accept($owner, $organization, 'tyskie', $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::productRows($organization));
        self::assertSame('unassigned', self::itemRow($item)['status']);
    }

    public function test_it_answers_404_for_a_proposal_that_does_not_exist_or_is_another_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreignItem = self::addItem($elsewhere, 'TYSKIE GOLD 0,5L', 'Tyskie');
        $product = self::addProduct($organization, 'Cola');

        self::accept($owner, $organization, 'tyskie', ['name' => 'Tyskie', 'measure' => 'volume']);
        self::assertResponseStatusCodeSame(404);
        self::assign($owner, $organization, 'tyskie', ['productId' => $product->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);
        self::dismiss($owner, $organization, 'tyskie');
        self::assertResponseStatusCodeSame(404);
        self::accept($owner, $organization, 'no-such-proposal', ['name' => 'Tyskie', 'measure' => 'volume']);
        self::assertResponseStatusCodeSame(404);

        // Another organization's proposal is untouched and still its own.
        self::assertSame('unassigned', self::itemRow($foreignItem)['status']);
        self::assertSame([], self::list($owner, $organization)->toArray());
        self::assertSame(['tyskie'], array_column(self::list($ownerElsewhere, $elsewhere)->toArray(), 'id'));
        self::assertSame(['Cola'], array_column(self::productRows($organization), 'name'), 'No product was created.');
    }

    #[DataProvider('managingRoles')]
    public function test_it_links_the_items_to_a_product_that_exists(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $item = self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');
        $beer = self::addProduct($organization, 'Tyskie Gold');

        self::assign($user, $organization, 'tyskie', ['productId' => $beer->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(['confirmed', $beer->getId()->toRfc4122(), '0.500000'], array_values(self::itemRow($item)));
        self::assertSame([], self::list($user, $organization)->toArray());
        self::assertCount(1, self::productRows($organization), 'No product was created.');
    }

    public function test_it_answers_404_for_a_product_that_is_another_organizations_and_422_for_a_bad_id_when_assigning(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $item = self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');
        $foreign = self::addProduct($elsewhere, 'Tyskie Gold');

        self::assign($owner, $organization, 'tyskie', ['productId' => $foreign->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);
        self::assign($owner, $organization, 'tyskie', ['productId' => 'nope']);
        self::assertResponseStatusCodeSame(422);
        self::assign($owner, $organization, 'tyskie', []);
        self::assertResponseStatusCodeSame(422);

        self::assertSame('unassigned', self::itemRow($item)['status']);
    }

    #[DataProvider('managingRoles')]
    public function test_it_dismisses_a_proposal_and_does_not_propose_the_same_name_again_for_its_items(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $item = self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');
        $kept = self::addItem($organization, 'COLA 1L', 'Cola');

        self::dismiss($user, $organization, 'tyskie');

        self::assertResponseStatusCodeSame(204);
        self::assertSame(['cola'], array_column(self::list($user, $organization)->toArray(), 'id'));
        self::assertSame('unassigned', self::itemRow($item)['status']);
        self::assertSame('tyskie', static::getContainer()->get(Connection::class)->fetchOne('SELECT dismissed_proposal_key FROM purchased_item WHERE id = :id', ['id' => $item]));
        self::assertNull(static::getContainer()->get(Connection::class)->fetchOne('SELECT dismissed_proposal_key FROM purchased_item WHERE id = :id', ['id' => $kept]));
        self::assertSame([], self::queuedJobs());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'list' => [static fn (User $user, Organization $organization, string $product): ResponseInterface => self::list($user, $organization)];
        yield 'accept' => [static fn (User $user, Organization $organization, string $product): ResponseInterface => self::accept($user, $organization, 'tyskie', ['name' => 'Tyskie', 'measure' => 'volume'])];
        yield 'assign' => [static fn (User $user, Organization $organization, string $product): ResponseInterface => self::assign($user, $organization, 'tyskie', ['productId' => $product])];
        yield 'dismiss' => [static fn (User $user, Organization $organization, string $product): ResponseInterface => self::dismiss($user, $organization, 'tyskie')];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_proposals_to_employees_and_deactivated_members_and_creates_no_product(\Closure $request): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $item = self::addItem($organization, 'TYSKIE GOLD 0,5L', 'Tyskie');
        $product = self::addProduct($organization, 'Cola');

        foreach ([$employee, $formerManager] as $user) {
            $request($user, $organization, $product->getId()->toRfc4122());
            self::assertResponseStatusCodeSame(403);
        }

        self::assertCount(1, self::productRows($organization), 'Only the one arranged: no refused request created a product.');
        self::assertSame('unassigned', self::itemRow($item)['status']);
        self::assertSame([], self::queuedJobs());
    }

    public function test_it_requires_a_signed_in_user_and_a_selected_organization(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']])->request('GET', '/api/product-proposals');
        self::assertResponseStatusCodeSame(401);

        static::clientFor($owner)->request('GET', '/api/product-proposals');
        self::assertResponseStatusCodeSame(400);
    }

    // ---- Requests ----

    private static function list(User $user, Organization $organization): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/product-proposals');
    }

    /** @param array<string, mixed> $body */
    private static function accept(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/product-proposals/'.$id.'/accept', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function assign(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/product-proposals/'.$id.'/assign', ['json' => $body]);
    }

    private static function dismiss(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/product-proposals/'.$id.'/dismiss', ['json' => []]);
    }

    // ---- Arranging ----

    private static function addCategory(Organization $organization, string $name): string
    {
        $owner = OrganizationMother::ownerOf($organization);
        $id = Uuid::v7();
        self::inTenant($organization, static function () use ($id, $name, $owner): void {
            $category = ProductCategory::create($id, $name, null, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());
            static::getContainer()->get(ProductCategoryRepositoryInterface::class)->add($category);
        });

        return $id->toRfc4122();
    }

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

    /**
     * Records an item that proposes a product, the way matching leaves it; with no proposed name it has none.
     *
     * @return string the item's id
     */
    private static function addItem(Organization $organization, string $name, ?string $proposedName, ?string $categoryId = null, ProductMeasure $measure = ProductMeasure::Volume): string
    {
        $id = Uuid::v7();
        self::inTenant($organization, static function () use ($id, $name, $proposedName, $categoryId, $measure): void {
            $category = null === $categoryId ? null : static::getContainer()->get(ProductCategoryRepositoryInterface::class)->findById(Uuid::fromString($categoryId));
            $item = PurchasedItem::record($id, new PurchasedItemDetails(
                Uuid::v7(), 1, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
                1, $name, null, null, 'szt.', '6', '10', '12.3', null, 1_000, 1_230, 230, '23', false,
            ), Clock::get()->now());
            $item->propose($proposedName, $measure, $category);
            static::getContainer()->get(PurchasedItemRepositoryInterface::class)->addAll([$item]);
        });

        return $id->toRfc4122();
    }

    /** @return array{status: string, product_id: ?string, package_size: ?string} */
    private static function itemRow(string $id): array
    {
        /** @var array{status: string, product_id: ?string, package_size: ?string} */
        return static::getContainer()->get(Connection::class)->fetchAssociative('SELECT status, product_id, package_size FROM purchased_item WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array{id: string, name: string, measure: string, category_id: ?string, reference_quantity: ?string}> */
    private static function productRows(Organization $organization): array
    {
        /** @var list<array{id: string, name: string, measure: string, category_id: ?string, reference_quantity: ?string}> */
        return static::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT id, name, measure, category_id, reference_quantity FROM product WHERE organization_id = :id ORDER BY name', ['id' => $organization->getId()->toRfc4122()]);
    }

    /** @return list<Envelope> what the last request queued on the in-memory `jobs` transport (ADR 0025 §7) */
    private static function queuedJobs(): array
    {
        $transport = static::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values([...$transport->getSent()]);
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
            $entityManager->clear();
        }
    }
}
