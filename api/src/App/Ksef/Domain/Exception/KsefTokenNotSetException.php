<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/** The KSeF connection has no token: it was removed. */
final class KsefTokenNotSetException extends KsefException
{
    public static function create(): self
    {
        return new self('The organization\'s KSeF token was removed: save a new one first.');
    }
}
