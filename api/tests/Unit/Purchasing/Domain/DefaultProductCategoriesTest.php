<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Domain;

use App\Purchasing\Domain\Model\DefaultProductCategories;
use App\Purchasing\Domain\Model\ProductCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultProductCategories::class)]
final class DefaultProductCategoriesTest extends TestCase
{
    public function test_it_gives_poland_the_tree_of_the_decision(): void
    {
        $tree = DefaultProductCategories::forCountry('pl');

        self::assertSame(
            ['Alkohol', 'Napoje bezalkoholowe', 'Kawa i herbata', 'Żywność', 'Chemia i środki czystości', 'Opakowania i artykuły jednorazowe', 'Wyposażenie', 'Media', 'Czynsz', 'Usługi', 'Kaucje', 'Inne'],
            array_column($tree, 'name'),
        );
        self::assertSame(['Wódka', 'Whisky', 'Rum', 'Gin', 'Likiery', 'Wino', 'Piwo'], $tree[0]['children']);
        self::assertSame(['Soki', 'Napoje gazowane', 'Woda', 'Syropy'], $tree[1]['children']);
        self::assertSame(['Prąd', 'Gaz', 'Woda', 'Internet i telefon'], $tree[7]['children']);
        self::assertSame(['Transport', 'Serwis', 'Księgowość'], $tree[9]['children']);
    }

    public function test_it_gives_other_countries_the_same_tree_in_english(): void
    {
        $polish = DefaultProductCategories::forCountry('PL');
        $english = DefaultProductCategories::forCountry('DE');

        self::assertSame(['Alcohol', 'Soft drinks', 'Coffee and tea', 'Food', 'Cleaning and chemicals', 'Packaging and disposables', 'Equipment', 'Utilities', 'Rent', 'Services', 'Deposits', 'Other'], array_column($english, 'name'));
        self::assertSame(
            array_map(static fn (array $top): int => \count($top['children']), $polish),
            array_map(static fn (array $top): int => \count($top['children']), $english),
        );
    }

    public function test_it_has_names_that_fit_and_are_unique_among_siblings(): void
    {
        foreach (['PL', 'DE'] as $country) {
            $tree = DefaultProductCategories::forCountry($country);
            $top = array_column($tree, 'name');
            self::assertSame($top, array_unique($top));
            foreach ($tree as $category) {
                self::assertSame($category['children'], array_unique($category['children']));
                foreach ([$category['name'], ...$category['children']] as $name) {
                    self::assertLessThanOrEqual(ProductCategory::NAME_MAX_LENGTH, mb_strlen($name), $name);
                }
            }
        }
    }
}
