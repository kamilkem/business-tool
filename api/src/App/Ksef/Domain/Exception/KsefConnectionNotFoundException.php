<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/** The organization has no KSeF connection: no token was ever saved. */
final class KsefConnectionNotFoundException extends KsefException
{
    public static function create(): self
    {
        return new self('The organization has no KSeF connection: save a KSeF token first.');
    }
}
