<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class CashFlowException extends CodedDomainException
{
    public static function module(): string
    {
        return 'cash_flow';
    }
}
