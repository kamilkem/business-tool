<?php

declare(strict_types=1);

namespace App\Tests\Api\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET, POST, PATCH, DELETE /product-categories and the default tree of a new organization (#238).
 * Organizations arranged by the factories start without categories; the server's time is mocked at
 * NOW (UTC).
 */
final class ProductCategoryApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_add_a_top_level_category(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, ['name' => '  Alcohol  ']);

        self::assertResponseStatusCodeSame(201);
        $category = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($category['id']));
        self::assertSame([
            'id' => $category['id'],
            'name' => 'Alcohol',
            'parentId' => null,
            'createdAt' => '2026-11-13T10:00:00+00:00',
        ], $category);
        self::assertSame([$category], self::list($actor, $organization)->toArray());
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_adds_subcategories_at_any_depth(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $alcohol = self::add($owner, $organization, ['name' => 'Alcohol'])->toArray();
        $whisky = self::add($owner, $organization, ['name' => 'Whisky', 'parentId' => $alcohol['id']])->toArray();
        self::assertResponseStatusCodeSame(201);
        $bourbon = self::add($owner, $organization, ['name' => 'Bourbon', 'parentId' => $whisky['id']])->toArray();
        self::assertResponseStatusCodeSame(201);

        self::assertSame($alcohol['id'], $whisky['parentId']);
        self::assertSame($whisky['id'], $bourbon['parentId']);
        self::assertSame(
            ['Alcohol' => null, 'Bourbon' => $whisky['id'], 'Whisky' => $alcohol['id']],
            array_column(self::list($owner, $organization)->toArray(), 'parentId', 'name'),
        );
    }

    public function test_it_lists_the_categories_flat_by_name(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $food = self::addCategory($organization, 'food');
        self::addCategory($organization, 'Whisky', self::addCategory($organization, 'Alcohol'));
        self::addCategory($organization, 'Beer', $food);

        $categories = self::list($owner, $organization)->toArray();

        self::assertSame(['Alcohol', 'Beer', 'food', 'Whisky'], array_column($categories, 'name'));
        self::assertSame(['id', 'name', 'parentId', 'createdAt'], array_keys($categories[0]));
    }

    public function test_it_answers_409_for_a_name_a_sibling_has_in_any_case(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $alcohol = self::addCategory($organization, 'Alcohol');
        self::addCategory($organization, 'Whisky', $alcohol);

        foreach ([['Alcohol', null], ['ALCOHOL', null], [' alcohol ', null], ['whisky', $alcohol], ['Whisky', $alcohol]] as [$name, $parent]) {
            self::add($owner, $organization, ['name' => $name, 'parentId' => $parent?->getId()->toRfc4122()]);
            self::assertResponseStatusCodeSame(409, $name);
        }

        $response = self::add($owner, $organization, ['name' => 'ALCOHOL']);
        self::assertSame('purchasing.product_category_name_taken', $response->toArray(false)['code']);
        // Taken only among siblings: the same name elsewhere is fine.
        self::add($owner, $organization, ['name' => 'Whisky']);
        self::assertResponseStatusCodeSame(201);
        self::add($owner, $organization, ['name' => 'Alcohol', 'parentId' => $alcohol->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidNewCategories(): iterable
    {
        yield 'no name' => [[]];
        yield 'null name' => [['name' => null]];
        yield 'empty name' => [['name' => '']];
        yield 'blank name' => [['name' => '   ']];
        yield 'long name' => [['name' => str_repeat('x', ProductCategory::NAME_MAX_LENGTH + 1)]];
        yield 'name of the wrong type' => [['name' => 5]];
        yield 'parent that is not a uuid' => [['name' => 'Alcohol', 'parentId' => 'not-a-uuid']];
        yield 'parent of the wrong type' => [['name' => 'Alcohol', 'parentId' => 5]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidNewCategories')]
    public function test_it_answers_422_with_violations_for_an_invalid_new_category(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::list($owner, $organization)->toArray());
    }

    public function test_it_accepts_a_name_of_64_characters_after_trimming(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $name = str_repeat('é', ProductCategory::NAME_MAX_LENGTH);

        self::add($owner, $organization, ['name' => ' '.$name.' ']);

        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_answers_422_for_a_parent_that_does_not_exist_or_is_another_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $foreign = self::addCategory($elsewhere, 'Alcohol');

        foreach ([Uuid::v7()->toRfc4122(), $foreign->getId()->toRfc4122()] as $parentId) {
            $response = self::add($owner, $organization, ['name' => 'Whisky', 'parentId' => $parentId]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('purchasing.product_category_parent_not_found', $response->toArray(false)['code']);
        }
        self::assertSame([], self::list($owner, $organization)->toArray());
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_rename_and_move_a_category(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $alcohol = self::addCategory($organization, 'Alcohol');
        $food = self::addCategory($organization, 'Food');
        $whisky = self::addCategory($organization, 'Whisky', $alcohol);
        $id = $whisky->getId()->toRfc4122();

        $renamed = self::update($actor, $organization, $id, ['name' => ' Scotch '])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Scotch', $alcohol->getId()->toRfc4122()], [$renamed['name'], $renamed['parentId']]);

        $moved = self::update($actor, $organization, $id, ['parentId' => $food->getId()->toRfc4122()])->toArray();
        self::assertSame(['Scotch', $food->getId()->toRfc4122()], [$moved['name'], $moved['parentId']]);

        $both = self::update($actor, $organization, $id, ['name' => 'Whisky', 'parentId' => $alcohol->getId()->toRfc4122()])->toArray();
        self::assertSame(['Whisky', $alcohol->getId()->toRfc4122()], [$both['name'], $both['parentId']]);

        $top = self::update($actor, $organization, $id, ['parentId' => null])->toArray();
        self::assertSame(['Whisky', null, $renamed['id']], [$top['name'], $top['parentId'], $id]);
        self::assertSame($top, self::byId($actor, $organization, $id));
    }

    public function test_it_keeps_what_the_body_does_not_send_and_accepts_the_same_values(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $alcohol = self::addCategory($organization, 'Alcohol');
        $whisky = self::addCategory($organization, 'Whisky', $alcohol);
        $before = self::byId($owner, $organization, $whisky->getId()->toRfc4122());

        self::update($owner, $organization, $whisky->getId()->toRfc4122(), []);
        self::assertResponseStatusCodeSame(200);
        self::update($owner, $organization, $whisky->getId()->toRfc4122(), ['name' => 'Whisky', 'parentId' => $alcohol->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(200);
        self::update($owner, $organization, $whisky->getId()->toRfc4122(), ['name' => 'WHISKY']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['WHISKY', $alcohol->getId()->toRfc4122()], [self::byId($owner, $organization, $whisky->getId()->toRfc4122())['name'], $before['parentId']]);
    }

    public function test_it_answers_409_for_a_name_the_new_siblings_have(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $alcohol = self::addCategory($organization, 'Alcohol');
        $food = self::addCategory($organization, 'Food');
        self::addCategory($organization, 'Beer', $alcohol);
        $other = self::addCategory($organization, 'beer', $food);

        // Renaming into a sibling's name.
        self::update($owner, $organization, $other->getId()->toRfc4122(), ['name' => 'BEER', 'parentId' => $alcohol->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(409);
        // Moving to where its name is taken.
        self::update($owner, $organization, $other->getId()->toRfc4122(), ['parentId' => $alcohol->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(409);
        // Renaming a top level category into another top level one's name.
        self::update($owner, $organization, $food->getId()->toRfc4122(), ['name' => 'alcohol']);
        self::assertResponseStatusCodeSame(409);

        self::assertSame(['beer', $food->getId()->toRfc4122()], [self::byId($owner, $organization, $other->getId()->toRfc4122())['name'], self::byId($owner, $organization, $other->getId()->toRfc4122())['parentId']]);
    }

    public function test_it_answers_409_for_a_move_under_itself_or_a_descendant(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $alcohol = self::addCategory($organization, 'Alcohol');
        $whisky = self::addCategory($organization, 'Whisky', $alcohol);
        $bourbon = self::addCategory($organization, 'Bourbon', $whisky);

        foreach ([$alcohol, $whisky, $bourbon] as $parent) {
            $response = self::update($owner, $organization, $alcohol->getId()->toRfc4122(), ['parentId' => $parent->getId()->toRfc4122()]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('purchasing.product_category_cycle', $response->toArray(false)['code']);
        }
        self::update($owner, $organization, $whisky->getId()->toRfc4122(), ['parentId' => $whisky->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(409);

        self::assertNull(self::byId($owner, $organization, $alcohol->getId()->toRfc4122())['parentId']);
        // Moving a branch elsewhere in the same tree is fine.
        self::update($owner, $organization, $bourbon->getId()->toRfc4122(), ['parentId' => $alcohol->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(200);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidUpdates(): iterable
    {
        yield 'null name' => [['name' => null]];
        yield 'empty name' => [['name' => '']];
        yield 'blank name' => [['name' => '   ']];
        yield 'long name' => [['name' => str_repeat('x', ProductCategory::NAME_MAX_LENGTH + 1)]];
        yield 'parent that is not a uuid' => [['parentId' => 'not-a-uuid']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidUpdates')]
    public function test_it_answers_422_with_violations_for_an_invalid_update(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $category = self::addCategory($organization, 'Alcohol');

        self::update($owner, $organization, $category->getId()->toRfc4122(), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Alcohol', self::byId($owner, $organization, $category->getId()->toRfc4122())['name']);
    }

    public function test_it_answers_422_for_a_new_parent_that_does_not_exist_or_is_another_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $category = self::addCategory($organization, 'Whisky');
        $foreign = self::addCategory($elsewhere, 'Alcohol');

        foreach ([Uuid::v7()->toRfc4122(), $foreign->getId()->toRfc4122()] as $parentId) {
            self::update($owner, $organization, $category->getId()->toRfc4122(), ['parentId' => $parentId]);
            self::assertResponseStatusCodeSame(422);
        }
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_delete_a_category_without_subcategories(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $alcohol = self::addCategory($organization, 'Alcohol');
        $whisky = self::addCategory($organization, 'Whisky', $alcohol);

        $response = self::delete($actor, $organization, $whisky->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertSame(['Alcohol'], array_column(self::list($actor, $organization)->toArray(), 'name'));
        // The parent is free to go once its last subcategory is gone, and its name can be used again.
        self::delete($actor, $organization, $alcohol->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);
        self::add($actor, $organization, ['name' => 'Alcohol']);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_answers_409_for_deleting_a_category_with_a_subcategory(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $alcohol = self::addCategory($organization, 'Alcohol');
        $whisky = self::addCategory($organization, 'Whisky', $alcohol);
        self::addCategory($organization, 'Bourbon', $whisky);

        foreach ([$alcohol, $whisky] as $category) {
            $response = self::delete($owner, $organization, $category->getId()->toRfc4122());
            self::assertResponseStatusCodeSame(409);
            self::assertSame('purchasing.product_category_in_use.has_subcategories', $response->toArray(false)['code']);
        }

        self::assertCount(3, self::list($owner, $organization)->toArray());
    }

    public function test_it_answers_404_for_an_unknown_category(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $unknown = Uuid::v7()->toRfc4122();

        self::update($owner, $organization, $unknown, ['name' => 'Rent']);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, 'not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        self::update($owner, $organization, 'not-a-uuid', ['name' => 'Rent']);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_hides_another_organizations_categories(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addCategory($organization, 'Rent');
        $foreign = self::addCategory($elsewhere, 'Alcohol');
        $foreignChild = self::addCategory($elsewhere, 'Whisky', $foreign);
        $id = $foreignChild->getId()->toRfc4122();
        // A member elsewhere too, as an employee: the selected organization decides what is visible.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::list($ownerElsewhere, $elsewhere)->toArray();

        self::assertSame(['Rent'], array_column(self::list($owner, $organization)->toArray(), 'name'));
        self::update($owner, $organization, $id, ['name' => 'Mine now']);
        self::assertResponseStatusCodeSame(404);
        self::update($owner, $organization, $id, ['parentId' => null]);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::delete($owner, $organization, $foreign->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        // Nor can another organization's category be a parent.
        self::add($owner, $organization, ['name' => 'Mine', 'parentId' => $foreign->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame($before, self::list($ownerElsewhere, $elsewhere)->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET collection' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::list($user, $organization)];
        yield 'POST' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::add($user, $organization, ['name' => 'Food'])];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::update($user, $organization, $id, ['name' => 'Food'])];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_categories_to_employees_and_deactivated_members(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $category = self::addCategory($organization, 'Rent');
        $before = self::list($owner, $organization)->toArray();

        foreach ([$employee, $formerManager, $formerOwner] as $user) {
            $request($user, $organization, $category->getId()->toRfc4122());
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::list($owner, $organization)->toArray());
    }

    public function test_it_refuses_an_employee_before_looking_at_the_category(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $unknown = Uuid::v7()->toRfc4122();

        self::update($employee, $organization, $unknown, ['name' => 'Food']);
        self::assertResponseStatusCodeSame(403);
        self::delete($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_requires_a_signed_in_user_and_a_selected_organization(): void
    {
        [$owner] = self::memberWithRole(MembershipRole::Owner);

        static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']])->request('GET', '/api/product-categories');
        self::assertResponseStatusCodeSame(401);

        static::clientFor($owner)->request('GET', '/api/product-categories');
        self::assertResponseStatusCodeSame(400);
    }

    public function test_it_gives_a_new_organization_the_default_tree_of_its_country(): void
    {
        $user = UserFactory::createOne();
        self::mockTime(self::NOW);

        $polish = self::createOrganization($user, null, ['name' => 'Corner Pub']);
        $german = self::createOrganization($user, null, ['name' => 'Eckkneipe', 'countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        $categories = self::list($user, $polish)->toArray();
        self::assertCount(30, $categories);
        self::assertSame(
            [
                'Alkohol' => ['Gin', 'Likiery', 'Piwo', 'Rum', 'Whisky', 'Wino', 'Wódka'],
                'Media' => ['Gaz', 'Internet i telefon', 'Prąd', 'Woda'],
                'Usługi' => ['Księgowość', 'Serwis', 'Transport'],
            ],
            array_intersect_key(self::tree($categories), array_flip(['Alkohol', 'Media', 'Usługi'])),
        );
        self::assertSame([['2026-11-13T10:00:00+00:00']], array_values(array_unique(array_map(static fn (array $category): array => [$category['createdAt']], $categories), \SORT_REGULAR)));

        $english = self::tree(self::list($user, $german)->toArray());
        self::assertSame(['Beer', 'Gin', 'Liqueurs', 'Rum', 'Vodka', 'Whisky', 'Wine'], $english['Alcohol'] ?? null);
        self::assertSame(['Alcohol', 'Cleaning and chemicals', 'Coffee and tea', 'Deposits', 'Equipment', 'Food', 'Other', 'Packaging and disposables', 'Rent', 'Services', 'Soft drinks', 'Utilities'], array_keys($english));
    }

    public function test_it_adds_the_defaults_to_the_new_organization_while_another_is_selected(): void
    {
        [$owner, $selected] = self::memberWithRole(MembershipRole::Owner);
        self::addCategory($selected, 'Rent');

        $created = self::createOrganization($owner, $selected, ['name' => 'Second Bar', 'countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        self::assertSame(['Rent'], array_column(self::list($owner, $selected)->toArray(), 'name'));
        self::assertCount(30, self::list($owner, $created)->toArray());
    }

    /**
     * @param array<array{id: string, name: string, parentId: ?string}> $categories
     *
     * @return array<string, list<string>> top level names => their subcategories' names, sorted
     */
    private static function tree(array $categories): array
    {
        $names = array_column($categories, 'name', 'id');
        $tree = [];
        foreach ($categories as $category) {
            if (null === $category['parentId']) {
                $tree[$category['name']] ??= [];
            } else {
                $tree[$names[$category['parentId']]][] = $category['name'];
            }
        }
        ksort($tree);
        foreach ($tree as &$children) {
            sort($children);
        }

        return $tree;
    }

    /** @return array<string, mixed> */
    private static function byId(User $user, Organization $organization, string $id): array
    {
        foreach (self::list($user, $organization)->toArray() as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }

        throw new \LogicException('Category expected.');
    }

    private static function list(User $user, Organization $organization): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/product-categories');
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/product-categories', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function update(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/product-categories/'.$id, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/product-categories/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function createOrganization(User $user, ?Organization $selected, array $body): Organization
    {
        $data = static::clientFor($user, $selected)->request('POST', '/api/organizations', ['json' => $body])->toArray();
        self::assertResponseStatusCodeSame(201);

        return static::getContainer()->get(EntityManagerInterface::class)->find(Organization::class, Uuid::fromString($data['id']))
            ?? throw new \LogicException('Organization expected.');
    }

    private static function addCategory(Organization $organization, string $name, ?ProductCategory $parent = null): ProductCategory
    {
        $owner = OrganizationMother::ownerOf($organization);
        $category = null;

        self::inTenant($organization, static function () use ($name, $parent, $owner, &$category): void {
            $categories = static::getContainer()->get(ProductCategoryRepositoryInterface::class);
            $category = ProductCategory::create(
                Uuid::v7(),
                $name,
                null === $parent ? null : $categories->findById($parent->getId()),
                null,
                $owner->getId(),
                MembershipRole::Owner,
                Clock::get()->now(),
            );
            $categories->add($category);
        });

        return $category ?? throw new \LogicException('Category expected.');
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
