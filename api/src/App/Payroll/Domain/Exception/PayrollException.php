<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class PayrollException extends CodedDomainException
{
    public static function module(): string
    {
        return 'payroll';
    }
}
