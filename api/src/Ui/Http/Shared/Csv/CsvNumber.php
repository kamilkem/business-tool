<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Csv;

use Symfony\Component\Intl\Currencies;

/**
 * Numbers for a CSV cell as decimal strings, never through a float (ADR 0010): money from integer
 * minor units, and time from seconds.
 */
final class CsvNumber
{
    /** 123456 PLN with ',' → "1234,56"; -5 → "-0,05"; a currency without minor units (JPY) has no decimals. No grouping. */
    public static function money(int $minorUnits, string $currency, string $decimalSeparator): string
    {
        return self::decimal($minorUnits, Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2, $decimalSeparator);
    }

    /** Seconds as hours with two decimals, rounded half up: 27000 → "7,50", 1 → "0,00". */
    public static function hours(int $seconds, string $decimalSeparator): string
    {
        $sign = $seconds < 0 ? -1 : 1;

        return self::decimal($sign * intdiv(abs($seconds) * 100 + 1800, 3600), 2, $decimalSeparator);
    }

    /** Seconds as hours, minutes and seconds: 27000 → "7:30:00", 90061 → "25:01:01". */
    public static function duration(int $seconds): string
    {
        $abs = abs($seconds);

        return \sprintf('%s%d:%02d:%02d', $seconds < 0 ? '-' : '', intdiv($abs, 3600), intdiv($abs % 3600, 60), $abs % 60);
    }

    /** An integer of 10^-$digits units as a decimal string. */
    public static function decimal(int $units, int $digits, string $decimalSeparator): string
    {
        $digitsOf = (string) abs($units);
        if (0 === $digits) {
            return ($units < 0 ? '-' : '').$digitsOf;
        }
        $digitsOf = str_pad($digitsOf, $digits + 1, '0', \STR_PAD_LEFT);

        return ($units < 0 ? '-' : '').substr($digitsOf, 0, -$digits).$decimalSeparator.substr($digitsOf, -$digits);
    }
}
