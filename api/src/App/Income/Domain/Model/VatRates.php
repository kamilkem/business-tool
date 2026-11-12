<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/**
 * The VAT rates an entry's breakdown may use, by the organization's country (ADR 0022 §6, ADR 0002).
 *
 * A country without rates here takes no VAT lines; a second country adds its rates to the list.
 * Rates have no validity period: a rate code is accepted for any date (ADR 0022, Consequences).
 */
final class VatRates
{
    /** @var array<string, list<array{string, bool}>> country code => [rate code, carries no VAT] */
    private const array BY_COUNTRY = [
        'PL' => [['23', false], ['8', false], ['5', false], ['0', true], ['zw', true]],
    ];

    /** @return list<VatRate> in the order a daily report prints them; empty for a country without rates */
    public static function forCountry(string $countryCode): array
    {
        return array_map(
            static fn (array $rate): VatRate => new VatRate($rate[0], $rate[1]),
            self::BY_COUNTRY[strtoupper($countryCode)] ?? [],
        );
    }

    public static function find(string $countryCode, string $code): ?VatRate
    {
        foreach (self::forCountry($countryCode) as $rate) {
            if ($rate->code === $code) {
                return $rate;
            }
        }

        return null;
    }
}
