<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/**
 * A purchase invoice's data breaks a rule of the aggregate: a required text is empty or too long, a
 * currency is not an ISO 4217 code, a VAT line uses a rate the organization's country does not have
 * or appears twice, the VAT in the tax currency is missing or superfluous, or an item breaks a rule
 * (or is given for a manual invoice).
 */
final class InvalidPurchaseInvoiceException extends ExpensesException
{
    public static function blank(string $field): self
    {
        return new self(\sprintf('The %s must not be blank.', $field), ['field' => $field], variant: __FUNCTION__);
    }

    public static function tooLong(string $field, int $max): self
    {
        return new self(\sprintf('The %s must be at most %d characters long.', $field, $max), ['field' => $field, 'max' => $max], variant: __FUNCTION__);
    }

    public static function invalidCurrency(string $field, string $currency): self
    {
        return new self(\sprintf('The %s "%s" is not a three-letter ISO 4217 code.', $field, $currency), ['field' => $field, 'currency' => $currency], variant: __FUNCTION__);
    }

    public static function missingExternalId(string $source): self
    {
        return new self(\sprintf('An invoice imported from "%s" needs its id there.', $source), ['source' => $source], variant: __FUNCTION__);
    }

    public static function notImported(): self
    {
        return new self('An imported invoice cannot have the source "manual".', [], variant: __FUNCTION__);
    }

    public static function unknownKind(string $kind): self
    {
        return new self(\sprintf('"%s" is not a kind of invoice.', $kind), ['kind' => $kind], variant: __FUNCTION__);
    }

    public static function correctedInvoiceOnManual(): self
    {
        return new self('An invoice entered by hand cannot refer to a corrected invoice\'s id in a source.', [], variant: __FUNCTION__);
    }

    public static function correctedInvoiceOnNonCorrection(string $kind): self
    {
        return new self(\sprintf('Only a correction refers to a corrected invoice; this invoice is "%s".', $kind), ['kind' => $kind], variant: __FUNCTION__);
    }

    public static function itemsOnManual(): self
    {
        return new self('An invoice entered by hand has no items.', [], variant: __FUNCTION__);
    }

    public static function unknownVatRate(string $rate, string $countryCode): self
    {
        return new self(\sprintf('"%s" is not a VAT rate in the country "%s".', $rate, $countryCode), ['rate' => $rate, 'countryCode' => $countryCode], variant: __FUNCTION__);
    }

    public static function duplicateVatRate(string $rate): self
    {
        return new self(\sprintf('The VAT rate "%s" appears more than once.', $rate), ['rate' => $rate], variant: __FUNCTION__);
    }

    public static function vatOnZeroRate(string $rate): self
    {
        return new self(\sprintf('The rate "%s" carries no VAT.', $rate), ['rate' => $rate], variant: __FUNCTION__);
    }

    public static function missingVatInTaxCurrency(string $rate, string $taxCurrency): self
    {
        return new self(\sprintf('The VAT for the rate "%s" must also be given in %s: the invoice is in another currency.', $rate, $taxCurrency), ['rate' => $rate, 'taxCurrency' => $taxCurrency], variant: __FUNCTION__);
    }

    public static function unexpectedVatInTaxCurrency(string $rate): self
    {
        return new self(\sprintf('The VAT for the rate "%s" is given in the tax currency, but the invoice is already in it.', $rate), ['rate' => $rate], variant: __FUNCTION__);
    }

    public static function emptyDocument(): self
    {
        return new self('The invoice document must not be empty.', [], variant: __FUNCTION__);
    }

    public static function invalid(string $field, string $value): self
    {
        return new self(\sprintf('The %s "%s" is not valid.', $field, $value), ['field' => $field, 'value' => $value], variant: __FUNCTION__);
    }
}
