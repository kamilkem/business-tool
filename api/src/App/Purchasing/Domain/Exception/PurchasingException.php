<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class PurchasingException extends CodedDomainException
{
    public static function module(): string
    {
        return 'purchasing';
    }
}
