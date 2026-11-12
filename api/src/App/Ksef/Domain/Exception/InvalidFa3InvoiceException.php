<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * An FA(3) document the import cannot read: not well-formed XML, a DOCTYPE, or a field it needs
 * missing, repeated or not matching the schema (an amount that is not `TKwotowy`, a date that does not
 * exist, an unknown invoice kind or currency). It points at a broken document or a parser gap, not at
 * the organization's settings.
 */
final class InvalidFa3InvoiceException extends KsefException
{
    public static function notXml(string $reason): self
    {
        return new self(\sprintf('The invoice is not well-formed XML: %s', $reason));
    }

    public static function doctype(): self
    {
        return new self('The invoice declares a DOCTYPE, which FA(3) does not allow.');
    }

    public static function missing(string $path): self
    {
        return new self(\sprintf('The invoice has no %s.', $path));
    }

    public static function repeated(string $path): self
    {
        return new self(\sprintf('The invoice has more than one %s.', $path));
    }

    public static function invalidValue(string $path, string $value, string $expected): self
    {
        return new self(\sprintf('The invoice\'s %s "%s" is not %s.', $path, $value, $expected));
    }
}
