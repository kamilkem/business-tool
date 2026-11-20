<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Http\Shared\Csv;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ui\Http\Shared\Csv\CsvNumber;

#[CoversClass(CsvNumber::class)]
final class CsvNumberTest extends TestCase
{
    /** @return iterable<string, array{int, string, string, string}> */
    public static function money(): iterable
    {
        yield 'grosze with a decimal comma' => [123_456, 'PLN', ',', '1234,56'];
        yield 'cents with a decimal point' => [123_456, 'EUR', '.', '1234.56'];
        yield 'less than one unit' => [5, 'PLN', ',', '0,05'];
        yield 'zero' => [0, 'PLN', ',', '0,00'];
        yield 'negative' => [-1_005, 'PLN', ',', '-10,05'];
        yield 'negative below one unit' => [-5, 'PLN', '.', '-0.05'];
        yield 'large, no grouping' => [2_147_483_647, 'PLN', ',', '21474836,47'];
        yield 'a currency without minor units' => [1_500, 'JPY', '.', '1500'];
        yield 'a currency with three decimals' => [12_345, 'KWD', '.', '12.345'];
    }

    #[DataProvider('money')]
    public function test_it_writes_minor_units_as_a_decimal_string_of_the_currency(int $minorUnits, string $currency, string $decimalSeparator, string $expected): void
    {
        self::assertSame($expected, CsvNumber::money($minorUnits, $currency, $decimalSeparator));
    }

    /** @return iterable<string, array{int, string}> */
    public static function hours(): iterable
    {
        yield 'seven and a half hours' => [27_000, '7,50'];
        yield 'one second' => [1, '0,00'];
        yield 'half of a hundredth rounds up' => [18, '0,01'];
        yield 'just below half of a hundredth rounds down' => [17, '0,00'];
        yield '20 minutes' => [1_200, '0,33'];
        yield '40 minutes' => [2_400, '0,67'];
        yield 'more than a day' => [90_061, '25,02'];
    }

    #[DataProvider('hours')]
    public function test_it_writes_seconds_as_hours_with_two_decimals_rounded_half_up(int $seconds, string $expected): void
    {
        self::assertSame($expected, CsvNumber::hours($seconds, ','));
    }

    public function test_it_writes_seconds_as_hours_minutes_and_seconds(): void
    {
        self::assertSame(['0:00:00', '7:30:00', '25:01:01', '0:00:59'], array_map(CsvNumber::duration(...), [0, 27_000, 90_061, 59]));
    }
}
