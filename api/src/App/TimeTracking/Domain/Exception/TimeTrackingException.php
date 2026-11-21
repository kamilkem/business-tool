<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class TimeTrackingException extends CodedDomainException
{
    public static function module(): string
    {
        return 'time_tracking';
    }
}
