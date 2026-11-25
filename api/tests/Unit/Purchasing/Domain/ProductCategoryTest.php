<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\Purchasing\Domain\Exception\InvalidProductCategoryNameException;
use App\Purchasing\Domain\Exception\ProductCategoryCycleException;
use App\Purchasing\Domain\Exception\ProductCategoryInUseException;
use App\Purchasing\Domain\Exception\ProductCategoryNameTakenException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\PurchasingAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProductCategory::class)]
#[CoversClass(PurchasingAccess::class)]
final class ProductCategoryTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';

    private Uuid $actorId;

    protected function setUp(): void
    {
        $this->actorId = Uuid::v7();
    }

    public function test_it_creates_a_top_level_category_with_a_trimmed_name(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);

        $category = ProductCategory::create($id, '  Alcohol  ', null, null, $this->actorId, MembershipRole::Manager, $now);

        self::assertSame($id, $category->getId());
        self::assertSame('Alcohol', $category->getName());
        self::assertNull($category->getParent());
        self::assertSame($now, $category->getCreatedAt());
    }

    public function test_it_creates_a_category_under_a_parent_at_any_depth(): void
    {
        $alcohol = $this->top('Alcohol');
        $whisky = $this->under($alcohol, 'Whisky');
        $bourbon = $this->under($whisky, 'Bourbon');
        $small = $this->under($bourbon, 'Small batch');

        self::assertSame($bourbon, $small->getParent());
        self::assertSame($whisky, $bourbon->getParent());
        self::assertSame($alcohol, $whisky->getParent());
    }

    public function test_it_lets_an_employee_create_no_category(): void
    {
        $this->expectException(PurchasingManagementForbiddenException::class);

        ProductCategory::create(Uuid::v7(), 'Alcohol', null, null, $this->actorId, MembershipRole::Employee, new \DateTimeImmutable(self::NOW));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('x', ProductCategory::NAME_MAX_LENGTH + 1)];
    }

    #[DataProvider('invalidNames')]
    public function test_it_refuses_an_invalid_name(string $name): void
    {
        $this->expectException(InvalidProductCategoryNameException::class);

        $this->top($name);
    }

    public function test_it_accepts_a_name_of_the_longest_length_after_trimming(): void
    {
        $name = str_repeat('é', ProductCategory::NAME_MAX_LENGTH);

        self::assertSame($name, $this->top(' '.$name.' ')->getName());
    }

    public function test_it_refuses_a_name_a_sibling_has(): void
    {
        $alcohol = $this->top('Alcohol');
        $sibling = $this->under($alcohol, 'Whisky');

        try {
            ProductCategory::create(Uuid::v7(), 'WHISKY', $alcohol, $sibling, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
            self::fail('The name should be taken.');
        } catch (ProductCategoryNameTakenException $e) {
            self::assertSame(['name' => 'WHISKY', 'parentId' => $alcohol->getId()->toRfc4122()], $e->errorParameters());
        }
    }

    public function test_it_reports_a_taken_top_level_name_without_a_parent(): void
    {
        $this->expectException(ProductCategoryNameTakenException::class);

        ProductCategory::create(Uuid::v7(), 'Food', null, $this->top('food'), $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_renames_a_category_and_keeps_the_letter_case_given(): void
    {
        $category = $this->top('Alcohol');

        $category->change('  Spirits ', null, null, $this->actorId, MembershipRole::Manager);

        self::assertSame('Spirits', $category->getName());
    }

    public function test_it_changes_only_the_letter_case_of_its_own_name(): void
    {
        $category = $this->top('Alcohol');

        // The repository finds the category itself as its own sibling.
        $category->change('ALCOHOL', null, $category, $this->actorId, MembershipRole::Owner);

        self::assertSame('ALCOHOL', $category->getName());
    }

    public function test_it_refuses_a_rename_to_a_siblings_name(): void
    {
        $category = $this->top('Alcohol');

        $this->expectException(ProductCategoryNameTakenException::class);

        $category->change('Food', null, $this->top('food'), $this->actorId, MembershipRole::Owner);
    }

    public function test_it_moves_under_another_category_and_to_the_top_level(): void
    {
        $alcohol = $this->top('Alcohol');
        $food = $this->top('Food');
        $whisky = $this->under($alcohol, 'Whisky');

        $whisky->change('Whisky', $food, null, $this->actorId, MembershipRole::Owner);
        self::assertSame($food, $whisky->getParent());

        $whisky->change('Whisky', null, null, $this->actorId, MembershipRole::Owner);
        self::assertNull($whisky->getParent());
    }

    public function test_it_refuses_a_name_the_new_siblings_have_when_moving(): void
    {
        $alcohol = $this->top('Alcohol');
        $food = $this->top('Food');
        $whisky = $this->under($alcohol, 'Whisky');
        $takenThere = $this->under($food, 'whisky');

        $this->expectException(ProductCategoryNameTakenException::class);

        $whisky->change('Whisky', $food, $takenThere, $this->actorId, MembershipRole::Owner);
    }

    public function test_it_refuses_to_move_under_itself(): void
    {
        $alcohol = $this->top('Alcohol');

        $this->expectException(ProductCategoryCycleException::class);

        $alcohol->change('Alcohol', $alcohol, null, $this->actorId, MembershipRole::Owner);
    }

    public function test_it_refuses_to_move_under_a_descendant_at_any_depth(): void
    {
        $alcohol = $this->top('Alcohol');
        $whisky = $this->under($alcohol, 'Whisky');
        $bourbon = $this->under($whisky, 'Bourbon');
        $small = $this->under($bourbon, 'Small batch');

        foreach ([$whisky, $bourbon, $small] as $descendant) {
            try {
                $alcohol->change('Alcohol', $descendant, null, $this->actorId, MembershipRole::Owner);
                self::fail('Moving under a descendant should be refused.');
            } catch (ProductCategoryCycleException $e) {
                self::assertSame(['categoryId' => $alcohol->getId()->toRfc4122(), 'parentId' => $descendant->getId()->toRfc4122()], $e->errorParameters());
            }
        }

        self::assertNull($alcohol->getParent());
    }

    public function test_it_moves_under_a_sibling_branch_and_an_ancestor(): void
    {
        $alcohol = $this->top('Alcohol');
        $whisky = $this->under($alcohol, 'Whisky');
        $bourbon = $this->under($whisky, 'Bourbon');
        $rum = $this->under($alcohol, 'Rum');

        $bourbon->change('Bourbon', $rum, null, $this->actorId, MembershipRole::Owner);
        self::assertSame($rum, $bourbon->getParent());

        $bourbon->change('Bourbon', $alcohol, null, $this->actorId, MembershipRole::Owner);
        self::assertSame($alcohol, $bourbon->getParent());
    }

    public function test_it_keeps_the_category_as_it_was_when_a_change_is_refused(): void
    {
        $alcohol = $this->top('Alcohol');
        $whisky = $this->under($alcohol, 'Whisky');

        try {
            $alcohol->change('Spirits', $whisky, null, $this->actorId, MembershipRole::Owner);
        } catch (ProductCategoryCycleException) {
        }

        self::assertSame('Alcohol', $alcohol->getName());
        self::assertNull($alcohol->getParent());
    }

    public function test_it_lets_an_employee_change_no_category(): void
    {
        $category = $this->top('Alcohol');

        $this->expectException(PurchasingManagementForbiddenException::class);

        $category->change('Spirits', null, null, $this->actorId, MembershipRole::Employee);
    }

    public function test_it_can_be_deleted_while_it_has_no_subcategory_and_no_product(): void
    {
        $this->expectNotToPerformAssertions();

        $this->top('Alcohol')->assertDeletable(false, false, $this->actorId, MembershipRole::Manager);
    }

    public function test_it_cannot_be_deleted_with_a_subcategory(): void
    {
        $category = $this->top('Alcohol');

        try {
            $category->assertDeletable(true, false, $this->actorId, MembershipRole::Owner);
            self::fail('A category with a subcategory cannot be deleted.');
        } catch (ProductCategoryInUseException $e) {
            self::assertSame('purchasing.product_category_in_use.has_subcategories', $e->errorCode());
            self::assertSame(['categoryId' => $category->getId()->toRfc4122()], $e->errorParameters());
        }
    }

    public function test_it_cannot_be_deleted_with_a_product(): void
    {
        $category = $this->top('Alcohol');

        try {
            $category->assertDeletable(false, true, $this->actorId, MembershipRole::Owner);
            self::fail('A category with a product cannot be deleted.');
        } catch (ProductCategoryInUseException $e) {
            self::assertSame('purchasing.product_category_in_use.has_products', $e->errorCode());
        }
    }

    public function test_it_lets_an_employee_delete_no_category(): void
    {
        $category = $this->top('Alcohol');

        $this->expectException(PurchasingManagementForbiddenException::class);

        $category->assertDeletable(false, false, $this->actorId, MembershipRole::Employee);
    }

    private function top(string $name): ProductCategory
    {
        return ProductCategory::create(Uuid::v7(), $name, null, null, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    private function under(ProductCategory $parent, string $name): ProductCategory
    {
        return ProductCategory::create(Uuid::v7(), $name, $parent, null, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }
}
