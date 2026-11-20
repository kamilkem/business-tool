<?php

declare(strict_types=1);

namespace App\Organization\Domain\Model;

/**
 * How the organization's CSV exports are written, so that its spreadsheet opens them as columns
 * (ADR 0002): a spreadsheet in a locale with a decimal comma (Polish, German, ...) splits on `;` and
 * reads `1234,56`; one with a decimal point splits on `,` and reads `1234.56`. The field separator
 * decides the decimal separator, as in those locales.
 */
enum CsvSeparator: string
{
    case Comma = 'comma';
    case Semicolon = 'semicolon';

    /** Countries whose usual number format has a decimal comma, so a spreadsheet there expects `;`. */
    private const array DECIMAL_COMMA_COUNTRIES = [
        'AT', 'BA', 'BE', 'BG', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IT', 'LT',
        'LU', 'LV', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'SE', 'SI', 'SK', 'UA',
    ];

    /** The default for a new organization in this country (ISO 3166-1 alpha-2). */
    public static function defaultFor(string $countryCode): self
    {
        return \in_array(strtoupper($countryCode), self::DECIMAL_COMMA_COUNTRIES, true) ? self::Semicolon : self::Comma;
    }

    /** The field separator character. */
    public function delimiter(): string
    {
        return match ($this) {
            self::Comma => ',',
            self::Semicolon => ';',
        };
    }

    /** The decimal separator that goes with this field separator. */
    public function decimalSeparator(): string
    {
        return match ($this) {
            self::Comma => '.',
            self::Semicolon => ',',
        };
    }
}
