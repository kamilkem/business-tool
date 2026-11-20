<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Exception\InvalidExpenseFilterException;
use App\Expenses\Domain\Repository\PurchaseInvoiceFilter;
use App\Shared\Domain\Time\BusinessPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PurchaseInvoiceFilter::class)]
final class PurchaseInvoiceFilterTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function sellers(): iterable
    {
        yield 'none' => [null, null, null];
        yield 'empty' => ['', null, null];
        yield 'blank' => ['   ', null, null];
        yield 'name, trimmed and lower-cased' => ['  Hurtownia NAPOJÓW ', 'hurtownia napojów', 'hurtownianapojów'];
        yield 'tax ID with dashes and spaces' => ['526-587 76-35', '526-587 76-35', '5265877635'];
        yield 'only dashes' => [' - ', '-', null];
    }

    #[DataProvider('sellers')]
    public function test_it_normalizes_the_seller_search(?string $given, ?string $seller, ?string $taxId): void
    {
        $filter = new PurchaseInvoiceFilter(self::period(), seller: $given);

        self::assertSame($seller, $filter->seller);
        self::assertSame($taxId, $filter->sellerTaxId());
    }

    public function test_it_takes_a_category_or_uncategorized(): void
    {
        $categoryId = Uuid::v7();

        $byCategory = new PurchaseInvoiceFilter(self::period(), $categoryId);
        $uncategorized = new PurchaseInvoiceFilter(self::period(), uncategorized: true);

        self::assertSame([$categoryId, false], [$byCategory->categoryId, $byCategory->uncategorized]);
        self::assertSame([null, true], [$uncategorized->categoryId, $uncategorized->uncategorized]);
    }

    public function test_it_refuses_a_category_and_uncategorized_together(): void
    {
        $this->expectException(InvalidExpenseFilterException::class);

        new PurchaseInvoiceFilter(self::period(), Uuid::v7(), true);
    }

    private static function period(): BusinessPeriod
    {
        return BusinessPeriod::fromStrings('2026-11-01', '2026-11-30');
    }
}
