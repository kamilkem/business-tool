<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * KSeF is only for organizations whose country is PL and whose tax ID is a valid NIP
 * (ADR 0002 §3, ADR 0023 §3).
 */
final class KsefNotAvailableException extends KsefException
{
    public static function outsidePoland(string $countryCode): self
    {
        return new self(\sprintf('KSeF is only available for organizations in Poland, not "%s".', $countryCode));
    }

    public static function invalidNip(): self
    {
        return new self('KSeF needs the organization\'s tax ID to be a valid NIP: set it in the organization\'s settings.');
    }
}
