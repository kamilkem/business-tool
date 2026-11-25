<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

/**
 * The product categories a new organization starts with, by its country (ADR 0041 §5, ADR 0002):
 * names in the country's language where one is listed here, English otherwise. Each entry is a top
 * level category with its subcategories, in the order they are meant to read. They are ordinary
 * categories afterwards, to rename, move or delete.
 */
final class DefaultProductCategories
{
    /** @var list<array{name: string, children: list<string>}> */
    private const array FALLBACK = [
        ['name' => 'Alcohol', 'children' => ['Vodka', 'Whisky', 'Rum', 'Gin', 'Liqueurs', 'Wine', 'Beer']],
        ['name' => 'Soft drinks', 'children' => ['Juices', 'Fizzy drinks', 'Water', 'Syrups']],
        ['name' => 'Coffee and tea', 'children' => []],
        ['name' => 'Food', 'children' => []],
        ['name' => 'Cleaning and chemicals', 'children' => []],
        ['name' => 'Packaging and disposables', 'children' => []],
        ['name' => 'Equipment', 'children' => []],
        ['name' => 'Utilities', 'children' => ['Electricity', 'Gas', 'Water', 'Internet and phone']],
        ['name' => 'Rent', 'children' => []],
        ['name' => 'Services', 'children' => ['Transport', 'Maintenance', 'Accounting']],
        ['name' => 'Deposits', 'children' => []],
        ['name' => 'Other', 'children' => []],
    ];

    /** @var array<string, list<array{name: string, children: list<string>}>> country code => tree, in the shape of FALLBACK */
    private const array BY_COUNTRY = [
        'PL' => [
            ['name' => 'Alkohol', 'children' => ['Wódka', 'Whisky', 'Rum', 'Gin', 'Likiery', 'Wino', 'Piwo']],
            ['name' => 'Napoje bezalkoholowe', 'children' => ['Soki', 'Napoje gazowane', 'Woda', 'Syropy']],
            ['name' => 'Kawa i herbata', 'children' => []],
            ['name' => 'Żywność', 'children' => []],
            ['name' => 'Chemia i środki czystości', 'children' => []],
            ['name' => 'Opakowania i artykuły jednorazowe', 'children' => []],
            ['name' => 'Wyposażenie', 'children' => []],
            ['name' => 'Media', 'children' => ['Prąd', 'Gaz', 'Woda', 'Internet i telefon']],
            ['name' => 'Czynsz', 'children' => []],
            ['name' => 'Usługi', 'children' => ['Transport', 'Serwis', 'Księgowość']],
            ['name' => 'Kaucje', 'children' => []],
            ['name' => 'Inne', 'children' => []],
        ],
    ];

    /** @return list<array{name: string, children: list<string>}> */
    public static function forCountry(string $countryCode): array
    {
        return self::BY_COUNTRY[strtoupper($countryCode)] ?? self::FALLBACK;
    }
}
