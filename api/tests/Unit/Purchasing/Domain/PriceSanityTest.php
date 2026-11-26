<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Purchasing\Domain\Matching\PriceSanity;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Shared\Domain\Time\BusinessDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** The price check of ADR 0045 §2: exact integer arithmetic, the median, the edges of the threshold and too little data. */
#[CoversClass(PriceSanity::class)]
final class PriceSanityTest extends TestCase
{
    public function test_it_prices_a_base_unit_in_thousandths_of_a_minor_unit(): void
    {
        // 6 bottles of 0.7 L for 48 780 grosze: 42 L at 11 614.285… grosze a litre.
        self::assertSame(11_614_286, PriceSanity::perBaseUnit(48_780, '6', '0.7'));
        self::assertSame(2_000_000, PriceSanity::perBaseUnit(2_000, '1', '1'));
        self::assertSame(500_000, PriceSanity::perBaseUnit(1_000, '2', '1'));
        self::assertSame(3_333_333, PriceSanity::perBaseUnit(1_000, '0.3', '1'), 'Rounded from 3 333 333.33.');
    }

    /** @param array{?int, ?string, ?string} $arguments */
    #[DataProvider('unpriced')]
    public function test_it_has_no_price_when_a_figure_is_missing_or_not_positive(array $arguments): void
    {
        self::assertNull(PriceSanity::perBaseUnit(...$arguments));
    }

    /** @return iterable<string, array{array{?int, ?string, ?string}}> */
    public static function unpriced(): iterable
    {
        yield 'no net amount' => [[null, '6', '0.7']];
        yield 'a zero net amount' => [[0, '6', '0.7']];
        yield 'a negative net amount' => [[-100, '6', '0.7']];
        yield 'no quantity' => [[1_000, null, '0.7']];
        yield 'a zero quantity' => [[1_000, '0', '0.7']];
        yield 'a negative quantity' => [[1_000, '-2', '0.7']];
        yield 'no package size' => [[1_000, '6', null]];
        yield 'a zero package size' => [[1_000, '6', '0']];
        yield 'not a decimal' => [[1_000, 'six', '0.7']];
        yield 'a base quantity below a millionth' => [[1_000, '0.000001', '0.000001']];
        yield 'an amount that does not fit an integer' => [[\PHP_INT_MAX, '1', '1']];
        yield 'a quantity that does not fit an integer' => [[1_000, '999999999999', '9999999999.999999']];
    }

    public function test_it_takes_the_median_of_the_prices(): void
    {
        self::assertNull(PriceSanity::median([]));
        self::assertSame(7, PriceSanity::median([7]));
        self::assertSame(3, PriceSanity::median([9, 1, 3]));
        self::assertSame(5, PriceSanity::median([10, 1, 2, 9]), 'The mean of 2 and 9, rounded down.');
        self::assertSame(4, PriceSanity::median([4, 4, 4, 100]));
    }

    public function test_it_finds_no_outlier_in_too_few_prices(): void
    {
        $sample = array_fill(0, PriceSanity::MIN_SAMPLE - 1, 1_000);

        self::assertFalse(PriceSanity::isOutlier(1_000_000, $sample));
        self::assertTrue(PriceSanity::isOutlier(1_000_000, [...$sample, 1_000]));
    }

    public function test_it_finds_no_outlier_without_a_positive_median(): void
    {
        self::assertFalse(PriceSanity::isOutlier(1_000, array_fill(0, PriceSanity::MIN_SAMPLE, 0)));
    }

    public function test_the_edges_of_the_threshold_are_fine_and_beyond_them_is_an_outlier(): void
    {
        $sample = array_fill(0, PriceSanity::MIN_SAMPLE, 3_000);

        self::assertFalse(PriceSanity::isOutlier(3_000, $sample));
        self::assertFalse(PriceSanity::isOutlier(9_000, $sample), 'Exactly three times the median.');
        self::assertTrue(PriceSanity::isOutlier(9_001, $sample));
        self::assertFalse(PriceSanity::isOutlier(1_000, $sample), 'Exactly a third of the median.');
        self::assertTrue(PriceSanity::isOutlier(999, $sample));
    }

    public function test_one_wild_price_in_the_sample_does_not_move_the_median(): void
    {
        $sample = [1_000, 1_100, 900, 1_050, 60_000];

        self::assertFalse(PriceSanity::isOutlier(1_200, $sample));
        self::assertTrue(PriceSanity::isOutlier(6_000, $sample));
    }

    public function test_it_prices_an_item_by_its_net_value_and_leaves_corrections_unpriced(): void
    {
        self::assertSame(11_614_286, PriceSanity::of(self::item(), '0.7'));
        self::assertSame(11_614_286, PriceSanity::of(self::item(net: null, gross: 60_000, vat: 11_220), '0.7'), 'Net is gross less VAT when it is not printed.');
        self::assertNull(PriceSanity::of(self::item(kind: 'correction'), '0.7'));
        self::assertNull(PriceSanity::of(self::item(beforeCorrection: true), '0.7'));
        self::assertNull(PriceSanity::of(self::item(net: null, gross: null, vat: null), '0.7'));
    }

    private static function item(string $kind = 'regular', ?int $net = 48_780, ?int $gross = 60_000, ?int $vat = 11_220, bool $beforeCorrection = false): PurchasedItem
    {
        return PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), 1, BusinessDate::fromString('2026-11-10'), $kind, 'PLN', '5265877635', 'Hurtownia Napojów',
            1, 'Jack Daniel\'s 0,7l', null, null, 'szt.', '6', '81.3', '100', null, $net, $gross, $vat, '23', $beforeCorrection,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
    }
}
