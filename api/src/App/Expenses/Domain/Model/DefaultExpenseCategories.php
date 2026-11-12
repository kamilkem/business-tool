<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

/**
 * The categories a new organization starts with, by its country (ADR 0002): names in the country's
 * language where one is listed here, English otherwise. They are ordinary categories afterwards, to
 * rename or archive.
 */
final class DefaultExpenseCategories
{
    /** @var list<string> */
    private const array FALLBACK = [
        'Goods for resale',
        'Food',
        'Beverages',
        'Rent',
        'Utilities',
        'Services',
        'Equipment',
        'Marketing',
        'Other',
    ];

    /** @var array<string, list<string>> country code => names, in the order of FALLBACK */
    private const array BY_COUNTRY = [
        'PL' => [
            'Towary do odsprzedaży',
            'Żywność',
            'Napoje',
            'Czynsz',
            'Media',
            'Usługi',
            'Wyposażenie',
            'Marketing',
            'Inne',
        ],
    ];

    /** @return list<string> */
    public static function forCountry(string $countryCode): array
    {
        return self::BY_COUNTRY[strtoupper($countryCode)] ?? self::FALLBACK;
    }
}
