<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\CreateProduct\CreateProductCommand;
use App\Purchasing\Application\Command\CreateProduct\CreateProductHandler;
use App\Purchasing\Application\Command\DeleteProduct\DeleteProductCommand;
use App\Purchasing\Application\Command\DeleteProduct\DeleteProductHandler;
use App\Purchasing\Application\Command\MergeProduct\MergeProductCommand;
use App\Purchasing\Application\Command\MergeProduct\MergeProductHandler;
use App\Purchasing\Application\Command\UpdateProduct\UpdateProductCommand;
use App\Purchasing\Application\Command\UpdateProduct\UpdateProductHandler;
use App\Purchasing\Application\Query\GetProduct\GetProductHandler;
use App\Purchasing\Application\Query\GetProduct\GetProductQuery;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Purchasing\Application\Query\ListProducts\ListProductsHandler;
use App\Purchasing\Application\Query\ListProducts\ListProductsQuery;
use App\Purchasing\Domain\Exception\InvalidProductReferenceQuantityException;
use App\Purchasing\Domain\Exception\ProductInUseException;
use App\Purchasing\Domain\Exception\ProductMergeIntoItselfException;
use App\Purchasing\Domain\Exception\ProductMergeTargetNotFoundException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Exception\UnknownProductCategoryException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\ProductMeasure;
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
 * An organization with an owner, a manager and an employee, the categories Alcohol › Whisky › Bourbon
 * and Food, and the products Jack Daniel's (Bourbon, volume), Cola (no category, volume) and Rent
 * (no unit).
 */
#[CoversClass(CreateProductHandler::class)]
#[CoversClass(UpdateProductHandler::class)]
#[CoversClass(DeleteProductHandler::class)]
#[CoversClass(MergeProductHandler::class)]
#[CoversClass(GetProductHandler::class)]
#[CoversClass(ListProductsHandler::class)]
#[CoversClass(ProductView::class)]
final class ProductHandlersTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const string JOINED = '2026-01-05 09:00:00';

    private InMemoryProductRepository $products;
    private InMemoryProductCategoryRepository $categories;
    private InMemoryPurchasedItemRepository $items;
    private InMemoryProductAliasRepository $aliases;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private ProductCategory $alcohol;
    private ProductCategory $whisky;
    private ProductCategory $bourbon;
    private ProductCategory $food;
    private Product $jack;
    private Product $cola;
    private Product $rent;

    protected function setUp(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $this->products = new InMemoryProductRepository();
        $this->categories = new InMemoryProductCategoryRepository();
        $this->items = new InMemoryPurchasedItemRepository();
        $this->aliases = new InMemoryProductAliasRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable(self::JOINED);
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->alcohol = $this->category('Alcohol', null);
        $this->whisky = $this->category('Whisky', $this->alcohol);
        $this->bourbon = $this->category('Bourbon', $this->whisky);
        $this->food = $this->category('Food', null);
        $this->jack = $this->arrange('Jack Daniel\'s', $this->bourbon, ProductMeasure::Volume, '0.7');
        $this->cola = $this->arrange('Cola', null, ProductMeasure::Volume, null);
        $this->rent = $this->arrange('Rent', null, ProductMeasure::None, null);

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
    public function test_it_creates_a_product_for_an_owner_or_manager(string $actor): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->create($this->user($actor), $id, '  Beer  ', ProductMeasure::Volume, '0.5', $this->food->getId()->toRfc4122(), true);

        $product = $this->products->findById(Uuid::fromString($id));
        self::assertNotNull($product);
        self::assertSame(['Beer', ProductMeasure::Volume, '0.5', true], [$product->getName(), $product->getMeasure(), $product->getReferenceQuantity(), $product->isExcluded()]);
        self::assertSame($this->food, $product->getCategory());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $product->getCreatedAt());
    }

    public function test_it_creates_a_product_without_a_category_and_with_the_default_reference_quantity(): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->create($this->owner, $id, 'Coffee', ProductMeasure::Mass, null, null, false);

        $product = $this->products->findById(Uuid::fromString($id));
        self::assertNotNull($product);
        self::assertNull($product->getCategory());
        self::assertSame('1', $product->getReferenceQuantity());
    }

    public function test_it_refuses_a_name_another_product_has_in_any_case(): void
    {
        $this->expectException(ProductNameTakenException::class);

        $this->create($this->owner, Uuid::v7()->toRfc4122(), ' COLA ', ProductMeasure::Volume, null, null, false);
    }

    public function test_it_refuses_an_unknown_category_when_creating_and_updating(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $categoryId) {
            try {
                $this->create($this->owner, Uuid::v7()->toRfc4122(), 'Beer', ProductMeasure::Volume, null, $categoryId, false);
                self::fail('The category should not be found.');
            } catch (UnknownProductCategoryException $e) {
                self::assertSame(['categoryId' => $categoryId], $e->errorParameters());
            }
            try {
                $this->update($this->owner, $this->cola->getId()->toRfc4122(), 'Cola', $categoryId, ProductMeasure::Volume, null, false);
                self::fail('The category should not be found.');
            } catch (UnknownProductCategoryException) {
            }
        }

        self::assertNull($this->products->findByName('Beer'));
        self::assertNull($this->cola->getCategory());
    }

    public function test_it_refuses_a_reference_quantity_that_does_not_fit_the_measure(): void
    {
        $this->expectException(InvalidProductReferenceQuantityException::class);

        $this->create($this->owner, Uuid::v7()->toRfc4122(), 'Electricity', ProductMeasure::None, '1', null, false);
    }

    public function test_it_refuses_an_employee_and_a_non_member_before_anything_else(): void
    {
        try {
            $this->create($this->employee, Uuid::v7()->toRfc4122(), 'Beer', ProductMeasure::Volume, null, 'not-a-uuid', false);
            self::fail('An employee should be refused.');
        } catch (PurchasingManagementForbiddenException) {
        }

        $this->expectException(OrganizationNotFoundException::class);

        $this->create(UserMother::any('stranger@example.com'), Uuid::v7()->toRfc4122(), 'Beer', ProductMeasure::Volume, null, null, false);
    }

    public function test_it_updates_a_product_in_one_change(): void
    {
        $this->update($this->manager, $this->cola->getId()->toRfc4122(), 'Coca-Cola', $this->food->getId()->toRfc4122(), ProductMeasure::Count, '6', true);

        self::assertSame(['Coca-Cola', ProductMeasure::Count, '6', true], [$this->cola->getName(), $this->cola->getMeasure(), $this->cola->getReferenceQuantity(), $this->cola->isExcluded()]);
        self::assertSame($this->food, $this->cola->getCategory());

        $this->update($this->owner, $this->cola->getId()->toRfc4122(), 'Coca-Cola', null, ProductMeasure::None, null, false);

        self::assertNull($this->cola->getCategory());
        self::assertNull($this->cola->getReferenceQuantity());
    }

    public function test_it_refuses_a_name_another_product_has_when_updating(): void
    {
        $this->expectException(ProductNameTakenException::class);

        $this->update($this->owner, $this->cola->getId()->toRfc4122(), 'rent', null, ProductMeasure::Volume, null, false);
    }

    public function test_it_reports_an_unknown_product_on_update_delete_and_merge(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $id) {
            foreach ([
                fn () => $this->update($this->owner, $id, 'Anything', null, ProductMeasure::Count, null, false),
                fn () => $this->delete($this->owner, $id),
                fn () => $this->merge($this->owner, $id, $this->cola->getId()->toRfc4122()),
            ] as $action) {
                try {
                    $action();
                    self::fail('The product should not be found.');
                } catch (ProductNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_it_deletes_a_product_without_items(): void
    {
        $this->delete($this->manager, $this->rent->getId()->toRfc4122());

        self::assertNull($this->products->findById($this->rent->getId()));
        self::assertNotNull($this->products->findById($this->cola->getId()));
    }

    public function test_it_refuses_to_delete_a_product_with_items(): void
    {
        $this->items->link($this->cola->getId());

        try {
            $this->delete($this->owner, $this->cola->getId()->toRfc4122());
            self::fail('A product with items should not be deleted.');
        } catch (ProductInUseException $e) {
            self::assertSame('purchasing.product_in_use', $e->errorCode());
        }

        self::assertNotNull($this->products->findById($this->cola->getId()));
    }

    public function test_it_merges_a_product_into_another_one_and_moves_its_items(): void
    {
        $this->items->link($this->cola->getId(), 3);
        $this->items->link($this->jack->getId(), 2);

        $this->merge($this->manager, $this->cola->getId()->toRfc4122(), $this->jack->getId()->toRfc4122());

        self::assertNull($this->products->findById($this->cola->getId()));
        self::assertSame(5, $this->items->linkedTo($this->jack->getId()));
        self::assertSame(0, $this->items->linkedTo($this->cola->getId()));
        // Its aliases move with its items (ADR 0042 §2).
        self::assertSame([[$this->cola->getId()->toRfc4122(), $this->jack->getId()->toRfc4122()]], $this->aliases->moves);
        // The target keeps its own settings.
        self::assertSame([ProductMeasure::Volume, '0.7', $this->bourbon], [$this->jack->getMeasure(), $this->jack->getReferenceQuantity(), $this->jack->getCategory()]);
    }

    public function test_it_merges_a_product_that_has_no_items(): void
    {
        $this->merge($this->owner, $this->rent->getId()->toRfc4122(), $this->cola->getId()->toRfc4122());

        self::assertNull($this->products->findById($this->rent->getId()));
    }

    public function test_it_refuses_to_merge_into_itself_or_an_unknown_product_and_changes_nothing(): void
    {
        $this->items->link($this->cola->getId(), 3);

        foreach ([
            [$this->cola->getId()->toRfc4122(), ProductMergeIntoItselfException::class],
            [Uuid::v7()->toRfc4122(), ProductMergeTargetNotFoundException::class],
            ['not-a-uuid', ProductMergeTargetNotFoundException::class],
        ] as [$into, $exception]) {
            try {
                $this->merge($this->owner, $this->cola->getId()->toRfc4122(), $into);
                self::fail('The merge should be refused.');
            } catch (\DomainException $e) {
                self::assertInstanceOf($exception, $e);
            }
        }

        self::assertNotNull($this->products->findById($this->cola->getId()));
        self::assertSame(3, $this->items->linkedTo($this->cola->getId()));
    }

    public function test_it_refuses_an_employee_to_update_delete_and_merge(): void
    {
        foreach ([
            fn () => $this->update($this->employee, $this->cola->getId()->toRfc4122(), 'Cola', null, ProductMeasure::Volume, null, false),
            fn () => $this->delete($this->employee, $this->cola->getId()->toRfc4122()),
            fn () => $this->merge($this->employee, $this->cola->getId()->toRfc4122(), $this->jack->getId()->toRfc4122()),
        ] as $action) {
            try {
                $action();
                self::fail('An employee should be refused.');
            } catch (PurchasingManagementForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_gets_a_product(): void
    {
        $view = $this->get($this->manager, $this->jack->getId()->toRfc4122());

        self::assertEquals(new ProductView(
            $this->jack->getId()->toRfc4122(),
            'Jack Daniel\'s',
            $this->bourbon->getId()->toRfc4122(),
            ProductMeasure::Volume,
            '0.7',
            false,
            new \DateTimeImmutable(self::JOINED),
        ), $view);
        self::assertNull($this->get($this->owner, $this->rent->getId()->toRfc4122())?->referenceQuantity);
        self::assertNull($this->get($this->owner, $this->cola->getId()->toRfc4122())?->categoryId);
    }

    public function test_it_gets_nothing_for_an_unknown_product_or_a_non_member_and_refuses_an_employee(): void
    {
        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner, 'not-a-uuid'));
        self::assertNull($this->get(UserMother::any('stranger@example.com'), $this->cola->getId()->toRfc4122()));

        $this->expectException(PurchasingManagementForbiddenException::class);

        $this->get($this->employee, $this->cola->getId()->toRfc4122());
    }

    public function test_it_lists_every_product_by_name(): void
    {
        self::assertSame(['Cola', 'Jack Daniel\'s', 'Rent'], $this->names($this->list($this->manager)));
    }

    public function test_it_lists_the_products_of_a_category_and_all_its_descendants(): void
    {
        $beer = $this->arrange('Beer', $this->alcohol, ProductMeasure::Volume, '0.5');
        $napkins = $this->arrange('Napkins', $this->food, ProductMeasure::Count, null);

        self::assertSame(['Beer', 'Jack Daniel\'s'], $this->names($this->list($this->owner, $this->alcohol->getId()->toRfc4122())));
        self::assertSame(['Jack Daniel\'s'], $this->names($this->list($this->owner, $this->whisky->getId()->toRfc4122())));
        self::assertSame(['Jack Daniel\'s'], $this->names($this->list($this->owner, $this->bourbon->getId()->toRfc4122())));
        self::assertSame([$napkins->getName()], $this->names($this->list($this->owner, $this->food->getId()->toRfc4122())));
        self::assertNotContains($beer->getName(), $this->names($this->list($this->owner, $this->whisky->getId()->toRfc4122())));
    }

    public function test_it_lists_no_products_for_an_unknown_category(): void
    {
        self::assertSame([], $this->list($this->owner, Uuid::v7()->toRfc4122()));
        self::assertSame([], $this->list($this->owner, 'not-a-uuid'));
    }

    public function test_it_searches_by_name_in_any_case_and_together_with_a_category(): void
    {
        $this->arrange('Jack Daniel\'s Honey', $this->whisky, ProductMeasure::Volume, '0.7');
        $this->arrange('Cola Zero', $this->food, ProductMeasure::Volume, null);

        self::assertSame(['Cola', 'Cola Zero'], $this->names($this->list($this->owner, null, 'COLA')));
        self::assertSame(['Cola Zero'], $this->names($this->list($this->owner, $this->food->getId()->toRfc4122(), 'cola')));
        self::assertSame(['Jack Daniel\'s', 'Jack Daniel\'s Honey'], $this->names($this->list($this->owner, $this->alcohol->getId()->toRfc4122(), 'daniel')));
        self::assertSame([], $this->list($this->owner, null, 'no such thing'));
    }

    public function test_it_lists_nothing_for_a_non_member_and_refuses_an_employee(): void
    {
        self::assertNull($this->handleList(new ListProductsQuery($this->organization->getId()->toRfc4122(), UserMother::any('stranger@example.com')->getId()->toRfc4122())));

        $this->expectException(PurchasingManagementForbiddenException::class);

        $this->list($this->employee);
    }

    private function category(string $name, ?ProductCategory $parent): ProductCategory
    {
        $category = ProductCategory::create(Uuid::v7(), $name, $parent, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable(self::JOINED));
        $this->categories->add($category);

        return $category;
    }

    private function arrange(string $name, ?ProductCategory $category, ProductMeasure $measure, ?string $referenceQuantity): Product
    {
        $product = Product::create(Uuid::v7(), $name, $category, $measure, $referenceQuantity, false, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable(self::JOINED));
        $this->products->add($product);

        return $product;
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }

    private function create(User $actor, string $id, string $name, ProductMeasure $measure, ?string $referenceQuantity, ?string $categoryId, bool $excluded): void
    {
        (new CreateProductHandler($this->products, $this->categories, $this->queryBus, new MockClock(self::NOW)))(
            new CreateProductCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id, $name, $measure->value, $referenceQuantity, $categoryId, $excluded),
        );
    }

    private function update(User $actor, string $id, string $name, ?string $categoryId, ProductMeasure $measure, ?string $referenceQuantity, bool $excluded): void
    {
        (new UpdateProductHandler($this->products, $this->categories, $this->queryBus))(
            new UpdateProductCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id, $name, $categoryId, $measure->value, $referenceQuantity, $excluded),
        );
    }

    private function delete(User $actor, string $id): void
    {
        (new DeleteProductHandler($this->products, $this->items, $this->queryBus))(
            new DeleteProductCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id),
        );
    }

    private function merge(User $actor, string $id, string $into): void
    {
        (new MergeProductHandler($this->products, $this->items, $this->aliases, $this->queryBus))(
            new MergeProductCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id, $into),
        );
    }

    private function get(User $actor, string $id): ?ProductView
    {
        return (new GetProductHandler($this->products, $this->queryBus))(
            new GetProductQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id),
        );
    }

    /** @return list<ProductView>|null */
    private function handleList(ListProductsQuery $query): ?array
    {
        return (new ListProductsHandler($this->products, $this->categories, $this->queryBus))($query);
    }

    /** @return list<ProductView> */
    private function list(User $actor, ?string $categoryId = null, ?string $search = null): array
    {
        return $this->handleList(new ListProductsQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $categoryId, $search)) ?? [];
    }

    /**
     * @param list<ProductView> $views
     *
     * @return list<string>
     */
    private static function names(array $views): array
    {
        return array_map(static fn (ProductView $view): string => $view->name, $views);
    }
}
