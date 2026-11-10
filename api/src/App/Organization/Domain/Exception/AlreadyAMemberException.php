<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

final class AlreadyAMemberException extends OrganizationException
{
    public static function of(string $userId, string $organizationId): self
    {
        return new self(\sprintf('User "%s" is already a member of organization "%s".', $userId, $organizationId));
    }
}
