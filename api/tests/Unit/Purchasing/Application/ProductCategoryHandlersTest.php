<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\AddProductCategory\AddProductCategoryCommand;
use App\Purchasing\Application\Command\AddProductCategory\AddProductCategoryHandler;
use App\Purchasing\Application\Command\DeleteProductCategory\DeleteProductCategoryCommand;
use App\Purchasing\Application\Command\DeleteProductCategory\DeleteProductCategoryHandler;
use App\Purchasing\Application\Command\UpdateProductCategory\UpdateProductCategoryCommand;
use App\Purchasing\Application\Command\UpdateProductCategory\UpdateProductCategoryHandler;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Application\Query\GetProductCategory\GetProductCategoryHandler;
use App\Purchasing\Application\Query\GetProductCategory\GetProductCategoryQuery;
use App\Purchasing\Application\Query\GetProductCategory\ProductCategoryView;
use App\Purchasing\Application\Query\ListProductCategories\ListProductCategoriesHandler;
use App\Purchasing\Application\Query\ListProductCategories\ListProductCategoriesQuery;
use App\Purchasing\Domain\Exception\ProductCategoryCycleException;
use App\Purchasing\Domain\Exception\ProductCategoryInUseException;
use App\Purchasing\Domain\Exception\ProductCategoryNameTakenException;
use App\Purchasing\Domain\Exception\ProductCategoryNotFoundException;
use App\Purchasing\Domain\Exception\ProductCategoryParentNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * An organization with an owner, a manager and an employee, and the tree Alcohol › Whisky and Food;
 * the Organization module answers through its real query over an in-memory repository.
 */
#[CoversClass(AddProductCategoryHandler::class)]
#[CoversClass(UpdateProductCategoryHandler::class)]
#[CoversClass(DeleteProductCategoryHandler::class)]
#[CoversClass(GetProductCategoryHandler::class)]
#[CoversClass(ListProductCategoriesHandler::class)]
#[CoversClass(ProductCategoryView::class)]
#[CoversClass(PurchasingActor::class)]
final class ProductCategoryHandlersTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const string JOINED = '2026-01-05 09:00:00';

    private InMemoryProductCategoryRepository $categories;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private ProductCategory $alcohol;
    private ProductCategory $whisky;
    private ProductCategory $food;

    protected function setUp(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $this->categories = new InMemoryProductCategoryRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable(self::JOINED);
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->alcohol = $this->arrange('Alcohol', null);
        $this->whisky = $this->arrange('Whisky', $this->alcohol);
        $this->food = $this->arrange('Food', null);

        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_adds_a_top_level_category_for_an_owner_or_manager(string $actor): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->add($this->user($actor), $id, '  Equipment ', null);

        $category = $this->categories->findById(Uuid::fromString($id));
        self::assertNotNull($category);
        self::assertSame('Equipment', $category->getName());
        self::assertNull($category->getParent());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $category->getCreatedAt());
    }

    public function test_it_adds_a_subcategory_under_any_category(): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->add($this->owner, $id, 'Bourbon', $this->whisky->getId()->toRfc4122());

        self::assertSame($this->whisky, $this->categories->findById(Uuid::fromString($id))?->getParent());
    }

    public function test_it_compares_names_among_siblings_only_and_case_insensitively(): void
    {
        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'whisky', $this->food->getId()->toRfc4122());
        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'Whisky', null);
        self::assertCount(5, $this->categories->findAll());

        foreach ([[' WHISKY ', $this->alcohol], ['alcohol', null], ['ALCOHOL', null]] as [$name, $parent]) {
            try {
                $this->add($this->owner, Uuid::v7()->toRfc4122(), $name, $parent?->getId()->toRfc4122());
                self::fail(\sprintf('"%s" should be taken.', $name));
            } catch (ProductCategoryNameTakenException) {
            }
        }
    }

    public function test_it_refuses_an_unknown_parent(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $parentId) {
            try {
                $this->add($this->owner, Uuid::v7()->toRfc4122(), 'Bourbon', $parentId);
                self::fail('The parent should not be found.');
            } catch (ProductCategoryParentNotFoundException $e) {
                self::assertSame(['parentId' => $parentId], $e->errorParameters());
            }
        }
    }

    public function test_it_refuses_an_employee_before_anything_else(): void
    {
        $this->expectException(PurchasingManagementForbiddenException::class);

        $this->add($this->employee, Uuid::v7()->toRfc4122(), 'Equipment', 'not-a-uuid');
    }

    public function test_it_refuses_someone_who_is_not_a_member(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->add(UserMother::any('stranger@example.com'), Uuid::v7()->toRfc4122(), 'Equipment', null);
    }

    public function test_it_renames_and_moves_a_category_in_one_change(): void
    {
        $this->update($this->manager, $this->whisky->getId()->toRfc4122(), 'Scotch', $this->food->getId()->toRfc4122());

        self::assertSame('Scotch', $this->whisky->getName());
        self::assertSame($this->food, $this->whisky->getParent());

        $this->update($this->owner, $this->whisky->getId()->toRfc4122(), 'Scotch', null);

        self::assertNull($this->whisky->getParent());
    }

    public function test_it_refuses_a_move_under_itself_or_a_descendant(): void
    {
        foreach ([$this->alcohol, $this->whisky] as $parent) {
            try {
                $this->update($this->owner, $this->alcohol->getId()->toRfc4122(), 'Alcohol', $parent->getId()->toRfc4122());
                self::fail('A cycle should be refused.');
            } catch (ProductCategoryCycleException) {
            }
        }

        self::assertNull($this->alcohol->getParent());
    }

    public function test_it_refuses_a_name_the_new_siblings_have(): void
    {
        $this->arrange('Whisky', $this->food);

        $this->expectException(ProductCategoryNameTakenException::class);

        $this->update($this->owner, $this->whisky->getId()->toRfc4122(), 'whisky', $this->food->getId()->toRfc4122());
    }

    public function test_it_lets_a_category_keep_its_name_when_moving_or_changing_case(): void
    {
        $this->update($this->owner, $this->whisky->getId()->toRfc4122(), 'WHISKY', $this->alcohol->getId()->toRfc4122());

        self::assertSame('WHISKY', $this->whisky->getName());
    }

    public function test_it_reports_an_unknown_category_or_parent_on_update(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $id) {
            try {
                $this->update($this->owner, $id, 'Anything', null);
                self::fail('The category should not be found.');
            } catch (ProductCategoryNotFoundException) {
            }
        }

        $this->expectException(ProductCategoryParentNotFoundException::class);

        $this->update($this->owner, $this->whisky->getId()->toRfc4122(), 'Whisky', Uuid::v7()->toRfc4122());
    }

    public function test_it_deletes_a_category_without_subcategories(): void
    {
        $this->delete($this->manager, $this->whisky->getId()->toRfc4122());

        self::assertNull($this->categories->findById($this->whisky->getId()));
        self::assertNotNull($this->categories->findById($this->alcohol->getId()));
        self::assertFalse($this->categories->hasSubcategories($this->alcohol->getId()));
    }

    public function test_it_refuses_to_delete_a_category_with_a_subcategory(): void
    {
        $this->expectException(ProductCategoryInUseException::class);

        try {
            $this->delete($this->owner, $this->alcohol->getId()->toRfc4122());
        } finally {
            self::assertNotNull($this->categories->findById($this->alcohol->getId()));
        }
    }

    public function test_it_reports_an_unknown_category_on_delete(): void
    {
        $this->expectException(ProductCategoryNotFoundException::class);

        $this->delete($this->owner, Uuid::v7()->toRfc4122());
    }

    public function test_it_refuses_an_employee_to_delete(): void
    {
        $this->expectException(PurchasingManagementForbiddenException::class);

        $this->delete($this->employee, $this->whisky->getId()->toRfc4122());
    }

    public function test_it_gets_a_category_with_its_parent_id(): void
    {
        $view = $this->get($this->manager, $this->whisky->getId()->toRfc4122());

        self::assertEquals(new ProductCategoryView(
            $this->whisky->getId()->toRfc4122(),
            'Whisky',
            $this->alcohol->getId()->toRfc4122(),
            new \DateTimeImmutable(self::JOINED),
        ), $view);
        self::assertNull($this->get($this->owner, $this->alcohol->getId()->toRfc4122())?->parentId);
    }

    public function test_it_gets_nothing_for_an_unknown_category_or_a_non_member_and_refuses_an_employee(): void
    {
        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner, 'not-a-uuid'));
        self::assertNull($this->get(UserMother::any('stranger@example.com'), $this->alcohol->getId()->toRfc4122()));

        $this->expectException(PurchasingManagementForbiddenException::class);

        $this->get($this->employee, $this->alcohol->getId()->toRfc4122());
    }

    public function test_it_lists_every_category_flat_by_name(): void
    {
        $views = $this->list($this->manager);

        self::assertSame(['Alcohol', 'Food', 'Whisky'], array_map(static fn (ProductCategoryView $view): string => $view->name, $views ?? []));
        self::assertSame([null, null, $this->alcohol->getId()->toRfc4122()], array_map(static fn (ProductCategoryView $view): ?string => $view->parentId, $views ?? []));
    }

    public function test_it_lists_nothing_for_a_non_member_and_refuses_an_employee(): void
    {
        self::assertNull($this->list(UserMother::any('stranger@example.com')));

        $this->expectException(PurchasingManagementForbiddenException::class);

        $this->list($this->employee);
    }

    private function arrange(string $name, ?ProductCategory $parent): ProductCategory
    {
        $category = ProductCategory::create(Uuid::v7(), $name, $parent, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable(self::JOINED));
        $this->categories->add($category);

        return $category;
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }

    private function add(User $actor, string $id, string $name, ?string $parentId): void
    {
        (new AddProductCategoryHandler($this->categories, $this->queryBus, new MockClock(self::NOW)))(
            new AddProductCategoryCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id, $name, $parentId),
        );
    }

    private function update(User $actor, string $id, string $name, ?string $parentId): void
    {
        (new UpdateProductCategoryHandler($this->categories, $this->queryBus))(
            new UpdateProductCategoryCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id, $name, $parentId),
        );
    }

    private function delete(User $actor, string $id): void
    {
        (new DeleteProductCategoryHandler($this->categories, $this->queryBus))(
            new DeleteProductCategoryCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id),
        );
    }

    private function get(User $actor, string $id): ?ProductCategoryView
    {
        return (new GetProductCategoryHandler($this->categories, $this->queryBus))(
            new GetProductCategoryQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id),
        );
    }

    /** @return list<ProductCategoryView>|null */
    private function list(User $actor): ?array
    {
        return (new ListProductCategoriesHandler($this->categories, $this->queryBus))(
            new ListProductCategoriesQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122()),
        );
    }
}
