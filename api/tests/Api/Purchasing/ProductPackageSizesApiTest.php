<?php

declare(strict_types=1);

namespace App\Tests\Api\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\ApplyPackageSize\ApplyPackageSizeCommand;
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
use App\User\Domain\Model\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET and POST /products/{id}/package-sizes: asking about the product's other items before a package size is
 * applied to them (ADR 0049, #271).
 */
final class ProductPackageSizesApiTest extends ApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lists_the_items_of_a_product_by_unit_and_package_size(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $cola = self::addProduct($organization, 'Cola');
        self::addItem($organization, $jack, 'szt.', '0.7', 'confirmed');
        self::addItem($organization, $jack, 'szt.', '0.7', 'assigned');
        self::addItem($organization, $jack, 'CO', null, 'confirmed');
        self::addItem($organization, $jack, 'co', null, 'suggested');
        self::addItem($organization, $jack, null, null, 'assigned');
        self::addItem($organization, $cola, 'szt.', '1', 'confirmed');
        self::addItem($organization, null, 'CO', null, 'unassigned');

        $response = self::sizes($user, $organization, $jack);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame($jack->getId()->toRfc4122(), $body['productId']);
        self::assertSame([
            ['co', 'CO', null, 2, 1],
            ['szt', 'szt.', '0.7', 2, 1],
            [null, null, null, 1, 0],
        ], array_map(static fn (array $group): array => [$group['unitKey'], $group['unit'], $group['packageSize'], $group['count'], $group['confirmedCount']], $body['groups']));
    }

    #[DataProvider('managingRoles')]
    public function test_it_sets_a_package_size_on_the_chosen_groups_only(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $bottle = self::addItem($organization, $jack, 'szt.', '0.7', 'confirmed');
        $confirmedCase = self::addItem($organization, $jack, 'CO', null, 'confirmed');
        $assignedCase = self::addItem($organization, $jack, 'co.', null, 'assigned');
        $carton = self::addItem($organization, $jack, 'kart.', null, 'confirmed');
        $otherSize = self::addItem($organization, $jack, 'CO', '4.2', 'confirmed');
        $loose = self::addItem($organization, null, 'CO', null, 'unassigned');

        $response = self::apply($user, $organization, $jack, ['packageSize' => '4.20', 'groups' => [['unitKey' => 'co', 'packageSize' => null]]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertSame([['confirmed', '4.200000'], ['assigned', '4.200000']], [self::row($confirmedCase), self::row($assignedCase)]);
        self::assertSame([['confirmed', '0.700000'], ['confirmed', null], ['confirmed', '4.200000'], ['unassigned', null]], [self::row($bottle), self::row($carton), self::row($otherSize), self::row($loose)]);
        // The link is never changed: the product stays.
        self::assertSame($jack->getId()->toRfc4122(), self::productOf($assignedCase));
        self::assertNull(self::productOf($loose));
    }

    public function test_it_changes_a_package_size_that_is_set_only_when_its_group_is_chosen(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $sameUnit = self::addItem($organization, $jack, 'szt.', '0.5', 'confirmed');
        $otherUnit = self::addItem($organization, $jack, 'CO', '3', 'confirmed');
        $untouched = self::addItem($organization, $jack, 'szt.', '0.7', 'confirmed');

        self::apply($owner, $organization, $jack, ['packageSize' => '0.7', 'groups' => [['unitKey' => 'szt', 'packageSize' => '0.5']]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('0.700000', self::row($sameUnit)[1]);
        self::assertSame('3.000000', self::row($otherUnit)[1]);
        self::assertSame('0.700000', self::row($untouched)[1]);
    }

    public function test_it_lists_and_sets_bundles_apart_from_plain_packages_of_the_same_size(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $plain = self::addItem($organization, $jack, 'CO', '0.7', 'confirmed');
        $bundle = self::addItem($organization, $jack, 'CO', '0.7', 'confirmed', 6);
        $empty = self::addItem($organization, $jack, 'CO', null, 'confirmed');

        $groups = self::sizes($owner, $organization, $jack)->toArray()['groups'];
        self::assertSame([[null, null], ['0.7', null], ['0.7', 6]], array_map(static fn (array $group): array => [$group['packageSize'], $group['bundleCount']], $groups));

        self::apply($owner, $organization, $jack, ['packageSize' => '0.5', 'bundleCount' => 20, 'groups' => [['unitKey' => 'co', 'packageSize' => '0.7', 'bundleCount' => 6]]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(['confirmed', '0.500000'], self::row($bundle));
        self::assertSame(20, self::bundleOf($bundle));
        self::assertSame([['confirmed', '0.700000'], ['confirmed', null]], [self::row($plain), self::row($empty)]);
        self::assertNull(self::bundleOf($plain));

        self::apply($owner, $organization, $jack, ['packageSize' => '0.5', 'bundleCount' => 1, 'groups' => [['unitKey' => 'co', 'packageSize' => null, 'bundleCount' => null]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::row($empty)[1]);
    }

    public function test_it_teaches_the_aliases_the_size_it_gave_confirmed_items(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        self::addItem($organization, $jack, 'CO', null, 'confirmed');

        self::apply($owner, $organization, $jack, ['packageSize' => '4.2', 'groups' => [['unitKey' => 'co', 'packageSize' => null]]]);

        /** @var list<array{string, ?string}> $rows */
        $rows = static::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT kind, package_size FROM product_alias WHERE product_id = :id ORDER BY kind', ['id' => $jack->getId()->toRfc4122()]);
        self::assertContains('4.200000', array_column($rows, 'package_size'));
    }

    public function test_it_answers_404_for_another_organizations_product_and_changes_nothing(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreignProduct = self::addProduct($elsewhere, 'Fanta');
        $foreignItem = self::addItem($elsewhere, $foreignProduct, 'CO', null, 'confirmed');
        $mine = self::addProduct($organization, 'Cola');
        self::addItem($organization, $mine, 'CO', null, 'confirmed');

        $response = self::sizes($owner, $organization, $foreignProduct);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['purchasing.product_not_found', ['productId' => $foreignProduct->getId()->toRfc4122()]], [$response->toArray(false)['code'], $response->toArray(false)['parameters']]);

        self::apply($owner, $organization, $foreignProduct, ['packageSize' => '6', 'groups' => [['unitKey' => 'co', 'packageSize' => null]]]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['confirmed', null], self::row($foreignItem));

        // Another organization's items are not in my product's groups, and my request does not reach them.
        $groups = self::sizes($owner, $organization, $mine)->toArray()['groups'];
        self::assertSame(1, $groups[0]['count']);
        self::apply($owner, $organization, $mine, ['packageSize' => '6', 'groups' => [['unitKey' => 'co', 'packageSize' => null]]]);
        self::assertSame(['confirmed', null], self::row($foreignItem));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequests(): iterable
    {
        $group = ['unitKey' => 'co', 'packageSize' => null];
        yield 'no package size' => [['groups' => [$group]]];
        yield 'null package size' => [['packageSize' => null, 'groups' => [$group]]];
        yield 'package size zero' => [['packageSize' => '0', 'groups' => [$group]]];
        yield 'negative package size' => [['packageSize' => '-1', 'groups' => [$group]]];
        yield 'package size that is not a number' => [['packageSize' => 'x', 'groups' => [$group]]];
        yield 'package size with too many decimals' => [['packageSize' => '0.1234567', 'groups' => [$group]]];
        yield 'no groups' => [['packageSize' => '1']];
        yield 'empty groups' => [['packageSize' => '1', 'groups' => []]];
        yield 'groups that are not a list' => [['packageSize' => '1', 'groups' => 'co']];
        yield 'a group with a size that is not a number' => [['packageSize' => '1', 'groups' => [['unitKey' => 'co', 'packageSize' => 'x']]]];
        yield 'bundle count of one' => [['packageSize' => '1', 'bundleCount' => 1, 'groups' => [$group]]];
        yield 'bundle count that is not a number' => [['packageSize' => '1', 'bundleCount' => 'x', 'groups' => [$group]]];
        yield 'too many groups' => [['packageSize' => '1', 'groups' => array_fill(0, ApplyPackageSizeCommand::MAX_TARGETS + 1, $group)]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidRequests')]
    public function test_it_answers_422_for_an_invalid_request_and_changes_nothing(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $item = self::addItem($organization, $jack, 'CO', null, 'confirmed');

        self::apply($owner, $organization, $jack, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['confirmed', null], self::row($item));
    }

    /** @return iterable<string, array{\Closure(User, Organization, Product): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET' => [static fn (User $user, Organization $organization, Product $product): ResponseInterface => self::sizes($user, $organization, $product)];
        yield 'POST' => [static fn (User $user, Organization $organization, Product $product): ResponseInterface => self::apply($user, $organization, $product, ['packageSize' => '6', 'groups' => [['unitKey' => 'co', 'packageSize' => null]]])];
    }

    /** @param \Closure(User, Organization, Product): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_employees_and_deactivated_members_and_changes_nothing(\Closure $request): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $jack = self::addProduct($organization, 'Jack Daniel\'s');
        $item = self::addItem($organization, $jack, 'CO', null, 'confirmed');

        foreach ([$employee, $formerManager] as $user) {
            $request($user, $organization, $jack);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame(['confirmed', null], self::row($item));
    }

    public function test_it_requires_a_signed_in_user_and_a_selected_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $jack = self::addProduct($organization, 'Jack Daniel\'s');

        static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']])->request('GET', '/api/products/'.$jack->getId()->toRfc4122().'/package-sizes');
        self::assertResponseStatusCodeSame(401);

        static::clientFor($owner)->request('GET', '/api/products/'.$jack->getId()->toRfc4122().'/package-sizes');
        self::assertResponseStatusCodeSame(400);
    }

    // ---- Requests ----

    private static function sizes(User $user, Organization $organization, Product $product): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/products/'.$product->getId()->toRfc4122().'/package-sizes');
    }

    /** @param array<string, mixed> $body */
    private static function apply(User $user, Organization $organization, Product $product, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/products/'.$product->getId()->toRfc4122().'/package-sizes', ['json' => $body]);
    }

    // ---- Arrangement ----

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

    /** @return string the item's id */
    private static function addItem(Organization $organization, ?Product $product, ?string $unit, ?string $packageSize, string $status, ?int $bundleCount = null): string
    {
        $id = Uuid::v7();
        self::inTenant($organization, static function () use ($id, $unit): void {
            $item = PurchasedItem::record($id, new PurchasedItemDetails(
                Uuid::v7(), 1, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
                1, 'JACK DANIELS 0,7L', null, null, $unit, '6', '10', '12.3', null, 1_000, 1_230, 230, '23', false,
            ), Clock::get()->now());
            static::getContainer()->get(PurchasedItemRepositoryInterface::class)->addAll([$item]);
        });
        static::getContainer()->get(Connection::class)->executeStatement('UPDATE purchased_item SET product_id = :product, status = :status, package_size = :size, bundle_count = :bundle WHERE id = :id', [
            'product' => $product?->getId()->toRfc4122(),
            'status' => $status,
            'size' => $packageSize,
            'bundle' => $bundleCount,
            'id' => $id->toRfc4122(),
        ]);

        return $id->toRfc4122();
    }

    /** @return array{string, ?string} status and package size */
    private static function row(string $id): array
    {
        /** @var array{status: string, package_size: ?string} $row */
        $row = static::getContainer()->get(Connection::class)->fetchAssociative('SELECT status, package_size FROM purchased_item WHERE id = :id', ['id' => $id]);

        return [$row['status'], $row['package_size']];
    }

    private static function bundleOf(string $id): ?int
    {
        $count = static::getContainer()->get(Connection::class)->fetchOne('SELECT bundle_count FROM purchased_item WHERE id = :id', ['id' => $id]);

        return null === $count || false === $count ? null : (int) $count;
    }

    private static function productOf(string $id): ?string
    {
        $productId = static::getContainer()->get(Connection::class)->fetchOne('SELECT product_id FROM purchased_item WHERE id = :id', ['id' => $id]);

        return \is_string($productId) ? $productId : null;
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
