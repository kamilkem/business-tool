<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/** KSeF rejected the saved token, or it no longer decrypts: nothing is imported until the owner saves a new one. */
final class KsefTokenRejectedException extends KsefException
{
    public static function create(): self
    {
        return new self('KSeF rejected the organization\'s token: the owner has to save a new one first.');
    }
}
