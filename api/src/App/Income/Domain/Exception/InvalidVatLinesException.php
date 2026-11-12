<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/**
 * VAT lines use the rates of the organization's country, each at most once, with
 * 0 <= VAT <= gross, and VAT = 0 for a 0% or exempt rate (ADR 0022 §6).
 */
final class InvalidVatLinesException extends IncomeException
{
    public static function noRatesForCountry(string $countryCode): self
    {
        return new self(\sprintf('No VAT rates are defined for the country "%s": an entry there takes no VAT lines.', $countryCode));
    }

    public static function unknownRate(string $rate, string $countryCode): self
    {
        return new self(\sprintf('"%s" is not a VAT rate in the country "%s".', $rate, $countryCode));
    }

    public static function duplicateRate(string $rate): self
    {
        return new self(\sprintf('The VAT rate "%s" appears more than once.', $rate));
    }

    public static function grossOutOfRange(string $rate, int $gross, int $max): self
    {
        return new self(\sprintf('The gross amount for the VAT rate "%s" must be between 0 and %d minor units, %d given.', $rate, $max, $gross));
    }

    public static function vatOutOfRange(string $rate, int $vat, int $gross): self
    {
        return new self(\sprintf('The VAT for the rate "%s" must be between 0 and the gross amount %d, %d given.', $rate, $gross, $vat));
    }

    public static function vatOnZeroRate(string $rate, int $vat): self
    {
        return new self(\sprintf('The rate "%s" carries no VAT, %d given.', $rate, $vat));
    }
}
