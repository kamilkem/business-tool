<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

use App\Shared\Domain\Exception\CodedDomainException;

abstract class OrganizationException extends CodedDomainException
{
    public static function module(): string
    {
        return 'organization';
    }
}
