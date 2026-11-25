<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

/**
 * A decimal number kept as a string, exactly as printed (ADR 0040): a quantity or a unit price that has
 * more decimals than the currency's minor units. Only checked and normalized here, never computed with
 * in PHP and never turned into a float.
 */
final class DecimalString
{
    private const string PATTERN = '/^([+-]?)(\d+)(?:\.(\d+))?$/D';

    /**
     * The value with no leading zeros, no trailing zeros in the fraction and no plus sign (`0.7`,
     * `-2`, `0`), or null when it is not a decimal number with at most the given digits.
     */
    public static function normalized(string $value, int $maxIntegerDigits, int $maxFractionDigits): ?string
    {
        if (1 !== preg_match(self::PATTERN, trim($value), $parts)) {
            return null;
        }
        $integer = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');
        if (\strlen($integer) > $maxIntegerDigits || \strlen($fraction) > $maxFractionDigits) {
            return null;
        }

        $digits = ('' === $integer ? '0' : $integer).('' === $fraction ? '' : '.'.$fraction);

        return '-' === $parts[1] && '0' !== $digits ? '-'.$digits : $digits;
    }

    /** A value read back from a NUMERIC column (`0.700000`) as normalized() gives it. */
    public static function trimmed(?string $stored): ?string
    {
        return null === $stored ? null : (self::normalized($stored, \PHP_INT_MAX, \PHP_INT_MAX) ?? throw new \LogicException(\sprintf('"%s" is not a stored decimal.', $stored)));
    }
}
