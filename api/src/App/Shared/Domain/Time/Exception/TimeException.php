<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class TimeException extends CodedDomainException
{
    public static function module(): string
    {
        return 'time';
    }
}
