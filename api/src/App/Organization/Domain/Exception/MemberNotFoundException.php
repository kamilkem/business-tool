<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

/** The user is not a member of the organization (or does not exist). */
final class MemberNotFoundException extends OrganizationException
{
    public static function of(string $userId, string $organizationId): self
    {
        return new self(\sprintf('User "%s" is not a member of organization "%s".', $userId, $organizationId));
    }
}
