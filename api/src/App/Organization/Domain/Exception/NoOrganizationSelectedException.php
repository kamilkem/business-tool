<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

final class NoOrganizationSelectedException extends OrganizationException
{
    public static function create(): self
    {
        return new self('No organization selected. Send the X-Organization-Id header.');
    }
}
