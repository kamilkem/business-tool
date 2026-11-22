<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

/**
 * The denominations a new organization starts with, by its currency (ADR 0033 §2): the coins and
 * notes in circulation, in minor units. A currency not listed here starts with none, and owners and
 * managers add its denominations themselves. They are ordinary denominations afterwards, to
 * deactivate or delete.
 */
final class DefaultDenominations
{
    /** @var array<string, array{coins: list<int>, notes: list<int>}> ISO 4217 => face values in minor units */
    private const array BY_CURRENCY = [
        'PLN' => [
            'coins' => [1, 2, 5, 10, 20, 50, 100, 200, 500],
            'notes' => [1_000, 2_000, 5_000, 10_000, 20_000, 50_000],
        ],
    ];

    /** @return list<array{value: int, kind: DenominationKind}> lowest value first */
    public static function forCurrency(string $currency): array
    {
        $values = self::BY_CURRENCY[strtoupper($currency)] ?? ['coins' => [], 'notes' => []];

        return [
            ...array_map(static fn (int $value): array => ['value' => $value, 'kind' => DenominationKind::Coin], $values['coins']),
            ...array_map(static fn (int $value): array => ['value' => $value, 'kind' => DenominationKind::Note], $values['notes']),
        ];
    }
}
