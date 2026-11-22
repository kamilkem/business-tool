<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/**
 * The payment methods a new organization starts with, by its country (ADR 0030 §2, ADR 0002): the
 * four fixed methods of ADR 0022 §4, named in the country's language where one is listed here, in
 * English otherwise. The first is marked as cash (ADR 0033 §9). They are ordinary methods
 * afterwards, to rename, reorder, mark, deactivate or delete.
 */
final class DefaultPaymentMethods
{
    /** @var list<array{name: string, cash: bool}> cash, card, transfer, voucher */
    private const array FALLBACK = [
        ['name' => 'Cash', 'cash' => true],
        ['name' => 'Card', 'cash' => false],
        ['name' => 'Transfer', 'cash' => false],
        ['name' => 'Voucher', 'cash' => false],
    ];

    /** @var array<string, list<array{name: string, cash: bool}>> country code => methods, in the order of FALLBACK */
    private const array BY_COUNTRY = [
        'PL' => [
            ['name' => 'Gotówka', 'cash' => true],
            ['name' => 'Karta', 'cash' => false],
            ['name' => 'Przelew', 'cash' => false],
            ['name' => 'Bon', 'cash' => false],
        ],
    ];

    /** @return list<array{name: string, cash: bool}> */
    public static function forCountry(string $countryCode): array
    {
        return self::BY_COUNTRY[strtoupper($countryCode)] ?? self::FALLBACK;
    }
}
