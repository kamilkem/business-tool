<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\Purchasing\Domain\Exception\InvalidProductNameException;
use App\Purchasing\Domain\Exception\InvalidProductReferenceQuantityException;
use App\Purchasing\Domain\Exception\ProductInUseException;
use App\Purchasing\Domain\Exception\ProductMergeIntoItselfException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Model\ProductMeasure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Product::class)]
#[CoversClass(ProductMeasure::class)]
final class ProductTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';

    private Uuid $actorId;

    protected function setUp(): void
    {
        $this->actorId = Uuid::v7();
    }

    public function test_it_creates_a_product_with_a_trimmed_name_and_the_default_reference_quantity(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);
        $whisky = ProductCategory::create(Uuid::v7(), 'Whisky', null, null, $this->actorId, MembershipRole::Owner, $now);

        $product = Product::create($id, '  Jack Daniel\'s  ', $whisky, ProductMeasure::Volume, null, false, null, $this->actorId, MembershipRole::Manager, $now);

        self::assertSame($id, $product->getId());
        self::assertSame('Jack Daniel\'s', $product->getName());
        self::assertSame($whisky, $product->getCategory());
        self::assertSame(ProductMeasure::Volume, $product->getMeasure());
        self::assertSame('1', $product->getReferenceQuantity());
        self::assertFalse($product->isExcluded());
        self::assertSame($now, $product->getCreatedAt());
    }

    /** @return iterable<string, array{ProductMeasure}> */
    public static function measuresWithAUnit(): iterable
    {
        yield 'volume' => [ProductMeasure::Volume];
        yield 'mass' => [ProductMeasure::Mass];
        yield 'count' => [ProductMeasure::Count];
    }

    #[DataProvider('measuresWithAUnit')]
    public function test_it_keeps_the_reference_quantity_of_a_measure_with_a_unit_in_normal_form(ProductMeasure $measure): void
    {
        self::assertTrue($measure->hasUnit());

        self::assertSame('0.5', $this->create($measure, '0.500000')->getReferenceQuantity());
        self::assertSame('2', $this->create($measure, '002.0')->getReferenceQuantity());
        self::assertSame('0.000001', $this->create($measure, '0.000001')->getReferenceQuantity());
        self::assertSame('1', $this->create($measure, null)->getReferenceQuantity());
    }

    public function test_it_gives_a_product_without_a_unit_no_reference_quantity(): void
    {
        self::assertFalse(ProductMeasure::None->hasUnit());

        self::assertNull($this->create(ProductMeasure::None, null)->getReferenceQuantity());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReferenceQuantities(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with decimals' => ['0.000'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['half a litre'];
        yield 'empty' => [''];
        yield 'with a comma' => ['0,5'];
        yield 'too many decimals' => ['0.0000001'];
        yield 'too many digits' => [str_repeat('9', 17)];
    }

    #[DataProvider('invalidReferenceQuantities')]
    public function test_it_refuses_a_reference_quantity_that_is_not_a_positive_decimal(string $value): void
    {
        try {
            $this->create(ProductMeasure::Volume, $value);
            self::fail('The reference quantity should be refused.');
        } catch (InvalidProductReferenceQuantityException $e) {
            self::assertSame('purchasing.invalid_product_reference_quantity.not_positive', $e->errorCode());
            self::assertSame(['referenceQuantity' => $value], $e->errorParameters());
        }
    }

    public function test_it_refuses_a_reference_quantity_for_a_product_without_a_unit(): void
    {
        try {
            $this->create(ProductMeasure::None, '1');
            self::fail('The reference quantity should be refused.');
        } catch (InvalidProductReferenceQuantityException $e) {
            self::assertSame('purchasing.invalid_product_reference_quantity.not_allowed_for_measure', $e->errorCode());
            self::assertSame(['referenceQuantity' => '1', 'measure' => 'none'], $e->errorParameters());
        }
    }

    public function test_it_refuses_a_blank_or_too_long_name(): void
    {
        foreach (['', '   ', str_repeat('x', Product::NAME_MAX_LENGTH + 1)] as $name) {
            try {
                $this->create(ProductMeasure::Count, null, $name);
                self::fail('The name should be refused.');
            } catch (InvalidProductNameException) {
            }
        }
        self::assertSame(Product::NAME_MAX_LENGTH, mb_strlen($this->create(ProductMeasure::Count, null, ' '.str_repeat('é', Product::NAME_MAX_LENGTH).' ')->getName()));
    }

    public function test_it_refuses_a_name_another_product_has(): void
    {
        $other = $this->create(ProductMeasure::Volume, null, 'Cola');

        $this->expectException(ProductNameTakenException::class);

        $this->create(ProductMeasure::Volume, null, 'cola', $other);
    }

    public function test_it_lets_an_employee_create_nothing(): void
    {
        $this->expectException(PurchasingManagementForbiddenException::class);

        Product::create(Uuid::v7(), 'Cola', null, ProductMeasure::Volume, null, false, null, $this->actorId, MembershipRole::Employee, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_changes_everything_the_owner_edits_at_once(): void
    {
        $product = $this->create(ProductMeasure::Volume, '0.5', 'Beer');
        $beer = ProductCategory::create(Uuid::v7(), 'Beer', null, null, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        $product->change(' Lager ', $beer, ProductMeasure::Mass, '2.50', true, null, $this->actorId, MembershipRole::Manager);

        self::assertSame('Lager', $product->getName());
        self::assertSame($beer, $product->getCategory());
        self::assertSame(ProductMeasure::Mass, $product->getMeasure());
        self::assertSame('2.5', $product->getReferenceQuantity());
        self::assertTrue($product->isExcluded());

        $product->change('Lager', null, ProductMeasure::None, null, false, null, $this->actorId, MembershipRole::Owner);

        self::assertNull($product->getCategory());
        self::assertNull($product->getReferenceQuantity());
        self::assertFalse($product->isExcluded());

        $product->change('Lager', null, ProductMeasure::Count, null, false, null, $this->actorId, MembershipRole::Owner);

        self::assertSame('1', $product->getReferenceQuantity());
    }

    public function test_it_lets_a_product_keep_its_name_or_change_its_case(): void
    {
        $product = $this->create(ProductMeasure::Volume, null, 'Cola');

        $product->change('COLA', null, ProductMeasure::Volume, '1', false, $product, $this->actorId, MembershipRole::Owner);

        self::assertSame('COLA', $product->getName());
    }

    public function test_it_changes_nothing_when_a_change_is_refused(): void
    {
        $product = $this->create(ProductMeasure::Volume, '0.5', 'Cola');
        $other = $this->create(ProductMeasure::Volume, null, 'Fanta');

        foreach ([
            fn () => $product->change('Fanta', null, ProductMeasure::Count, '3', true, $other, $this->actorId, MembershipRole::Owner),
            fn () => $product->change('Sprite', null, ProductMeasure::None, '3', true, null, $this->actorId, MembershipRole::Owner),
            fn () => $product->change('Sprite', null, ProductMeasure::Count, '-3', true, null, $this->actorId, MembershipRole::Owner),
            fn () => $product->change('', null, ProductMeasure::Count, '3', true, null, $this->actorId, MembershipRole::Owner),
            fn () => $product->change('Sprite', null, ProductMeasure::Count, '3', true, null, $this->actorId, MembershipRole::Employee),
        ] as $change) {
            try {
                $change();
                self::fail('The change should be refused.');
            } catch (\DomainException) {
            }
        }

        self::assertSame(['Cola', ProductMeasure::Volume, '0.5', false], [$product->getName(), $product->getMeasure(), $product->getReferenceQuantity(), $product->isExcluded()]);
    }

    public function test_it_is_deleted_only_while_no_item_refers_to_it(): void
    {
        $product = $this->create(ProductMeasure::Volume, null);

        $product->assertDeletable(false, $this->actorId, MembershipRole::Manager);

        try {
            $product->assertDeletable(true, $this->actorId, MembershipRole::Owner);
            self::fail('A product with items should not be deleted.');
        } catch (ProductInUseException $e) {
            self::assertSame(['productId' => $product->getId()->toRfc4122()], $e->errorParameters());
        }

        $this->expectException(PurchasingManagementForbiddenException::class);

        $product->assertDeletable(false, $this->actorId, MembershipRole::Employee);
    }

    public function test_it_merges_into_another_product_but_not_into_itself(): void
    {
        $product = $this->create(ProductMeasure::Volume, null, 'Cola');
        $other = $this->create(ProductMeasure::None, null, 'Fanta');

        $product->assertCanMergeInto($other, $this->actorId, MembershipRole::Manager);

        $this->expectException(ProductMergeIntoItselfException::class);

        $product->assertCanMergeInto($product, $this->actorId, MembershipRole::Owner);
    }

    public function test_it_lets_an_employee_merge_nothing(): void
    {
        $product = $this->create(ProductMeasure::Volume, null, 'Cola');

        $this->expectException(PurchasingManagementForbiddenException::class);

        $product->assertCanMergeInto($this->create(ProductMeasure::Volume, null, 'Fanta'), $this->actorId, MembershipRole::Employee);
    }

    private function create(ProductMeasure $measure, ?string $referenceQuantity, string $name = 'Jack Daniel\'s', ?Product $sibling = null): Product
    {
        return Product::create(Uuid::v7(), $name, null, $measure, $referenceQuantity, false, $sibling, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }
}
