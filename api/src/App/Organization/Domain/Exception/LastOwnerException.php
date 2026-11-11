<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

/** The change would leave the organization without an owner; another owner must be appointed first. */
final class LastOwnerException extends OrganizationException
{
    public static function of(string $organizationId): self
    {
        return new self(\sprintf('Organization "%s" must keep at least one owner. Make another member an owner first.', $organizationId));
    }
}
