<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/**
 * The payment methods a new organization starts with, by its country (ADR 0030 §2, ADR 0002): the
 * four fixed methods of ADR 0022 §4, named in the country's language where one is listed here, in
 * English otherwise. They are ordinary methods afterwards, to rename, reorder, deactivate or delete.
 */
final class DefaultPaymentMethods
{
    /** @var list<string> cash, card, transfer, voucher */
    private const array FALLBACK = ['Cash', 'Card', 'Transfer', 'Voucher'];

    /** @var array<string, list<string>> country code => names, in the order of FALLBACK */
    private const array BY_COUNTRY = [
        'PL' => ['Gotówka', 'Karta', 'Przelew', 'Bon'],
    ];

    /** @return list<string> */
    public static function forCountry(string $countryCode): array
    {
        return self::BY_COUNTRY[strtoupper($countryCode)] ?? self::FALLBACK;
    }
}
