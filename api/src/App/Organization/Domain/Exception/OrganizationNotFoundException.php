<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

/** Also thrown for organizations the user is not a member of, so their existence does not leak. */
final class OrganizationNotFoundException extends OrganizationException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('Organization "%s" not found.', $id));
    }
}
