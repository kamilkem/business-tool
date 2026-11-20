<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/**
 * A purchase invoice's data breaks a rule of the aggregate: a required text is empty or too long, a
 * currency is not an ISO 4217 code, a VAT line uses a rate the organization's country does not have
 * or appears twice, or the VAT in the tax currency is missing or superfluous.
 */
final class InvalidPurchaseInvoiceException extends ExpensesException
{
    public static function blank(string $field): self
    {
        return new self(\sprintf('The %s must not be blank.', $field));
    }

    public static function tooLong(string $field, int $max): self
    {
        return new self(\sprintf('The %s must be at most %d characters long.', $field, $max));
    }

    public static function invalidCurrency(string $field, string $currency): self
    {
        return new self(\sprintf('The %s "%s" is not a three-letter ISO 4217 code.', $field, $currency));
    }

    public static function missingExternalId(string $source): self
    {
        return new self(\sprintf('An invoice imported from "%s" needs its id there.', $source));
    }

    public static function notImported(): self
    {
        return new self('An imported invoice cannot have the source "manual".');
    }

    public static function unknownKind(string $kind): self
    {
        return new self(\sprintf('"%s" is not a kind of invoice.', $kind));
    }

    public static function correctedInvoiceOnManual(): self
    {
        return new self('An invoice entered by hand cannot refer to a corrected invoice\'s id in a source.');
    }

    public static function correctedInvoiceOnNonCorrection(string $kind): self
    {
        return new self(\sprintf('Only a correction refers to a corrected invoice; this invoice is "%s".', $kind));
    }

    public static function unknownVatRate(string $rate, string $countryCode): self
    {
        return new self(\sprintf('"%s" is not a VAT rate in the country "%s".', $rate, $countryCode));
    }

    public static function duplicateVatRate(string $rate): self
    {
        return new self(\sprintf('The VAT rate "%s" appears more than once.', $rate));
    }

    public static function vatOnZeroRate(string $rate): self
    {
        return new self(\sprintf('The rate "%s" carries no VAT.', $rate));
    }

    public static function missingVatInTaxCurrency(string $rate, string $taxCurrency): self
    {
        return new self(\sprintf('The VAT for the rate "%s" must also be given in %s: the invoice is in another currency.', $rate, $taxCurrency));
    }

    public static function unexpectedVatInTaxCurrency(string $rate): self
    {
        return new self(\sprintf('The VAT for the rate "%s" is given in the tax currency, but the invoice is already in it.', $rate));
    }

    public static function emptyDocument(): self
    {
        return new self('The invoice document must not be empty.');
    }
}
