<?php

declare(strict_types=1);

namespace App\Reporting\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class ReportingException extends CodedDomainException
{
    public static function module(): string
    {
        return 'reporting';
    }
}
