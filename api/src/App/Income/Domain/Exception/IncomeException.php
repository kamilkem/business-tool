<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class IncomeException extends CodedDomainException
{
    public static function module(): string
    {
        return 'income';
    }
}
