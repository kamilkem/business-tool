<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Purchasing\Domain\Exception\InvalidBundleCountException;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;
use App\Purchasing\Domain\Model\PackageSize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The size of one package and, for a bundle, how many of them one unit holds (ADR 0048 §2). */
#[CoversClass(PackageSize::class)]
final class PackageSizeTest extends TestCase
{
    /** @return iterable<string, array{string, ?int, string}> single size, bundle count, total */
    public static function totals(): iterable
    {
        yield 'no bundle: the size is per unit' => ['0.7', null, '0.7'];
        yield 'a crate of 20 half-litre bottles' => ['0.5', 20, '10'];
        yield 'a six-pack of 0.33 L' => ['0.33', 6, '1.98'];
        yield 'a carton of 24 cans' => ['0.330000', 24, '7.92'];
        yield 'six decimals stay exact' => ['0.333333', 3, '0.999999'];
        yield 'a size per unit in normal form' => ['007.50', null, '7.5'];
        yield 'the largest count' => ['1', 9_999, '9999'];
        yield 'the smallest count' => ['1', 2, '2'];
    }

    #[DataProvider('totals')]
    public function test_it_gives_the_size_per_unit_of_the_quantity(string $single, ?int $count, string $total): void
    {
        $size = PackageSize::of($single, $count);

        self::assertSame($total, $size->total());
        self::assertSame($count, $size->bundleCount);
    }

    public function test_it_keeps_the_size_of_one_package_in_normal_form(): void
    {
        self::assertSame('0.5', PackageSize::of('0.500000', 20)->single);
    }

    public function test_it_is_the_same_total_whether_entered_as_a_bundle_or_as_one_size(): void
    {
        self::assertTrue(PackageSize::of('0.5', 20)->sameTotalAs(PackageSize::of('10')));
        self::assertTrue(PackageSize::of('0.5', 20)->sameTotalAs(PackageSize::of('2', 5)));
        self::assertFalse(PackageSize::of('0.5', 20)->sameTotalAs(PackageSize::of('0.5')));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSizes(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'text' => ['a bottle'];
        yield 'seven decimals' => ['0.0000001'];
        yield 'seventeen integer digits' => ['10000000000000000'];
    }

    #[DataProvider('invalidSizes')]
    public function test_it_refuses_a_single_size_that_is_not_a_positive_decimal(string $given): void
    {
        $this->expectException(InvalidPackageSizeException::class);

        PackageSize::of($given, 6);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidCounts(): iterable
    {
        yield 'one is no bundle' => [1];
        yield 'zero' => [0];
        yield 'negative' => [-6];
        yield 'above the largest' => [10_000];
    }

    #[DataProvider('invalidCounts')]
    public function test_it_refuses_a_count_outside_two_to_9999(int $count): void
    {
        try {
            PackageSize::of('0.5', $count);
            self::fail('The count is out of range.');
        } catch (InvalidBundleCountException $e) {
            self::assertSame('purchasing.invalid_bundle_count.out_of_range', $e->errorCode());
            self::assertSame(['bundleCount' => $count], $e->errorParameters());
        }
    }

    public function test_it_refuses_a_bundle_whose_total_is_a_trillion_base_units_or_more(): void
    {
        self::assertSame('999999999999', PackageSize::of('499999999999.5', 2)->total());

        try {
            PackageSize::of('500000000000', 2);
            self::fail('The total is too large.');
        } catch (InvalidBundleCountException $e) {
            self::assertSame('purchasing.invalid_bundle_count.too_large', $e->errorCode());
        }
        $this->expectException(InvalidBundleCountException::class);
        PackageSize::of('9999999999999999', 2);
    }

    public function test_it_builds_from_a_total_when_it_is_a_valid_size_and_from_nothing_otherwise(): void
    {
        self::assertSame('3', PackageSize::ofTotal('3.0')?->total());
        self::assertNull(PackageSize::ofTotal('0'));
        self::assertNull(PackageSize::ofTotal('-1'));
        self::assertNull(PackageSize::ofTotal('three'));
    }
}
