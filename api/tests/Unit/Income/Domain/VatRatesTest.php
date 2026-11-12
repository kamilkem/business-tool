<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Domain;

use App\Income\Domain\Model\VatRate;
use App\Income\Domain\Model\VatRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VatRates::class)]
final class VatRatesTest extends TestCase
{
    public function test_it_gives_the_polish_rates_in_the_order_of_a_daily_report(): void
    {
        self::assertEquals([
            new VatRate('23', false),
            new VatRate('8', false),
            new VatRate('5', false),
            new VatRate('0', true),
            new VatRate('zw', true),
        ], VatRates::forCountry('PL'));
    }

    public function test_it_reads_the_country_code_in_any_case(): void
    {
        self::assertEquals(VatRates::forCountry('PL'), VatRates::forCountry('pl'));
    }

    public function test_it_gives_no_rates_for_a_country_without_any_defined(): void
    {
        self::assertSame([], VatRates::forCountry('DE'));
        self::assertNull(VatRates::find('DE', '19'));
    }

    public function test_it_finds_a_rate_by_its_code(): void
    {
        self::assertEquals(new VatRate('zw', true), VatRates::find('PL', 'zw'));
        self::assertEquals(new VatRate('8', false), VatRates::find('PL', '8'));
    }

    public function test_it_finds_no_rate_the_country_does_not_have(): void
    {
        self::assertNull(VatRates::find('PL', '22'));
        self::assertNull(VatRates::find('PL', 'ZW'));
        self::assertNull(VatRates::find('PL', '08'));
    }
}
