<?php

declare(strict_types=1);

namespace App\User\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class UserException extends CodedDomainException
{
    public static function module(): string
    {
        return 'user';
    }
}
