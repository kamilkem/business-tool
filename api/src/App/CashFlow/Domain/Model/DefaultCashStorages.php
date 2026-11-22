<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/**
 * The cash storages a new organization starts with, by its country (ADR 0033 §3, ADR 0002): a cash
 * box, counted at each close, and a safe the takings are deposited into, named in the country's
 * language where one is listed here, in English otherwise (as DefaultPaymentMethods). They are
 * ordinary storages afterwards, to rename, change, deactivate or delete.
 */
final class DefaultCashStorages
{
    /** @var list<array{name: string, mode: CashStorageMode}> */
    private const array FALLBACK = [
        ['name' => 'Cash box', 'mode' => CashStorageMode::Counted],
        ['name' => 'Safe', 'mode' => CashStorageMode::Deposit],
    ];

    /** @var array<string, list<array{name: string, mode: CashStorageMode}>> country code => storages */
    private const array BY_COUNTRY = [
        'PL' => [
            ['name' => 'Kasetka', 'mode' => CashStorageMode::Counted],
            ['name' => 'Sejf', 'mode' => CashStorageMode::Deposit],
        ],
    ];

    /** @return list<array{name: string, mode: CashStorageMode}> */
    public static function forCountry(string $countryCode): array
    {
        return self::BY_COUNTRY[strtoupper($countryCode)] ?? self::FALLBACK;
    }
}
