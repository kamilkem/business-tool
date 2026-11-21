<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

final class OrganizationManagementForbiddenException extends OrganizationException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not manage this organization. Only owners and managers can.', $userId), ['userId' => $userId]);
    }
}
