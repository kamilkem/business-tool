<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Infrastructure\Fa3;

use App\Ksef\Domain\Exception\InvalidFa3InvoiceException;
use App\Ksef\Infrastructure\Fa3\Fa3Amount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Fa3AmountTest extends TestCase
{
    /** @return iterable<string, array{string, int, int}> */
    public static function amounts(): iterable
    {
        yield 'two decimals' => ['1234.56', 2, 123456];
        yield 'one decimal' => ['12.3', 2, 1230];
        yield 'no decimals' => ['40', 2, 4000];
        yield 'zero' => ['0', 2, 0];
        yield 'zero with decimals' => ['0.00', 2, 0];
        yield 'negative zero' => ['-0.00', 2, 0];
        yield 'a grosz' => ['0.01', 2, 1];
        yield 'negative' => ['-123.54', 2, -12354];
        yield 'negative below one' => ['-0.04', 2, -4];
        yield 'white space collapsed' => [" 10.50\n", 2, 1050];
        yield 'largest TKwotowy' => ['9999999999999999.99', 2, 999999999999999999];
        yield 'smallest TKwotowy' => ['-9999999999999999.99', 2, -999999999999999999];
        yield 'currency without decimals' => ['1250.00', 0, 1250];
        yield 'currency without decimals, no fraction' => ['1250', 0, 1250];
        yield 'currency with three decimals' => ['12.50', 3, 12500];
        yield 'currency with four decimals' => ['-1.5', 4, -15000];
    }

    #[DataProvider('amounts')]
    public function test_it_converts_an_fa3_amount_to_minor_units(string $value, int $fractionDigits, int $expected): void
    {
        self::assertSame($expected, Fa3Amount::toMinorUnits($value, $fractionDigits, 'Fa/P_15'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidAmounts(): iterable
    {
        yield 'empty' => ['', 2];
        yield 'three decimals' => ['1.234', 2];
        yield 'decimal comma' => ['12,50', 2];
        yield 'thousands separator' => ['1,000.00', 2];
        yield 'leading zero' => ['012.50', 2];
        yield 'plus sign' => ['+12.50', 2];
        yield 'exponent' => ['1e3', 2];
        yield 'trailing dot' => ['12.', 2];
        yield 'leading dot' => ['.50', 2];
        yield 'seventeen integer digits' => ['10000000000000000', 2];
        yield 'text' => ['abc', 2];
        yield 'fraction its currency does not have' => ['12.50', 0];
        yield 'too large for 64 bits in its currency' => ['9999999999999999.99', 4];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_what_is_not_an_exact_fa3_amount(string $value, int $fractionDigits): void
    {
        $this->expectException(InvalidFa3InvoiceException::class);
        $this->expectExceptionMessage('Fa/P_13_1');

        Fa3Amount::toMinorUnits($value, $fractionDigits, 'Fa/P_13_1');
    }
}
