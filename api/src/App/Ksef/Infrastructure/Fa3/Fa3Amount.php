<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Fa3;

use App\Ksef\Domain\Exception\InvalidFa3InvoiceException;

/**
 * An FA(3) amount (`TKwotowy`: `xsd:decimal`, at most 16 integer and 2 fraction digits, an optional
 * minus) to integer minor units, by string operations only: no float on the way (ADR 0010).
 *
 * The number of minor-unit digits belongs to the currency (2 for PLN and EUR, 0 for JPY, 3 for KWD),
 * as the frontend reads it from CLDR. An amount with more fraction digits than its currency has
 * (12.50 JPY) cannot be stored exactly and is rejected rather than rounded.
 */
final class Fa3Amount
{
    /** The `TKwotowy` pattern of schemat_FA(3)_v1-0E.xsd, anchored. */
    private const string PATTERN = '/^(-?)([1-9]\d{0,15}|0)(?:\.(\d{1,2}))?$/D';

    /**
     * @param string $path           where the amount is, for the error message (e.g. `Fa/P_13_1`)
     * @param int    $fractionDigits the currency's number of minor-unit digits
     *
     * @throws InvalidFa3InvoiceException
     */
    public static function toMinorUnits(string $value, int $fractionDigits, string $path): int
    {
        // xsd:decimal collapses white space.
        $value = trim($value);
        if (1 !== preg_match(self::PATTERN, $value, $parts)) {
            throw InvalidFa3InvoiceException::invalidValue($path, $value, 'an FA(3) amount (TKwotowy)');
        }
        [, $sign, $integer] = $parts;
        $fraction = str_pad($parts[3] ?? '', 2, '0');

        if ($fractionDigits >= 2) {
            $digits = $integer.$fraction.str_repeat('0', $fractionDigits - 2);
        } else {
            if ('' !== trim(substr($fraction, $fractionDigits), '0')) {
                throw InvalidFa3InvoiceException::invalidValue($path, $value, \sprintf('an amount with at most %d decimal places, as its currency has', $fractionDigits));
            }
            $digits = $integer.substr($fraction, 0, $fractionDigits);
        }

        $digits = ltrim($digits, '0');
        $minor = '' === $digits ? 0 : filter_var($digits, \FILTER_VALIDATE_INT);
        if (false === $minor) {
            throw InvalidFa3InvoiceException::invalidValue($path, $value, 'an amount that fits in 64 bits of minor units');
        }

        return '-' === $sign ? -$minor : $minor;
    }
}
