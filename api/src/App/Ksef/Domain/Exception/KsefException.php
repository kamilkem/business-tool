<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

/** Base of the KSeF module's exceptions (ADR 0024: KSeF is its own module). */
abstract class KsefException extends CodedDomainException
{
    public static function module(): string
    {
        return 'ksef';
    }
}
