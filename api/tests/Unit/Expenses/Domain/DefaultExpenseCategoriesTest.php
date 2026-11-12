<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Model\DefaultExpenseCategories;
use App\Expenses\Domain\Model\ExpenseCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultExpenseCategories::class)]
final class DefaultExpenseCategoriesTest extends TestCase
{
    public function test_it_gives_polish_names_for_poland_in_any_case(): void
    {
        self::assertSame(
            ['Towary do odsprzedaży', 'Żywność', 'Napoje', 'Czynsz', 'Media', 'Usługi', 'Wyposażenie', 'Marketing', 'Inne'],
            DefaultExpenseCategories::forCountry('PL'),
        );
        self::assertSame(DefaultExpenseCategories::forCountry('PL'), DefaultExpenseCategories::forCountry('pl'));
    }

    public function test_it_gives_english_names_for_a_country_without_a_list(): void
    {
        self::assertSame(
            ['Goods for resale', 'Food', 'Beverages', 'Rent', 'Utilities', 'Services', 'Equipment', 'Marketing', 'Other'],
            DefaultExpenseCategories::forCountry('DE'),
        );
    }

    public function test_it_gives_names_a_category_accepts_once_each(): void
    {
        foreach (['PL', 'DE'] as $country) {
            $names = DefaultExpenseCategories::forCountry($country);
            self::assertSame($names, array_values(array_unique($names)));
            foreach ($names as $name) {
                self::assertSame(trim($name), $name);
                self::assertLessThanOrEqual(ExpenseCategory::NAME_MAX_LENGTH, mb_strlen($name));
            }
        }
    }
}
