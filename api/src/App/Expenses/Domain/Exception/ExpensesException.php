<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class ExpensesException extends CodedDomainException
{
    public static function module(): string
    {
        return 'expenses';
    }
}
