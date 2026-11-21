<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * The document is not an FA(3) invoice (e.g. FA(2), PEF, FA_RR): only FA(3) is imported (ADR 0023 §7).
 * The sync skips other form codes by the metadata before it downloads them; this is the parser's own check.
 */
final class UnsupportedInvoiceFormException extends KsefException
{
    public static function rootElement(string $namespace, string $name): self
    {
        return new self(\sprintf('The document "{%s}%s" is not an FA(3) invoice.', $namespace, $name), ['namespace' => $namespace, 'name' => $name], variant: __FUNCTION__);
    }

    public static function formCode(string $systemCode, string $schemaVersion): self
    {
        return new self(\sprintf('The form "%s" (schema "%s") is not supported; only "FA (3)" (schema "1-0E") is.', $systemCode, $schemaVersion), ['systemCode' => $systemCode, 'schemaVersion' => $schemaVersion], variant: __FUNCTION__);
    }
}
