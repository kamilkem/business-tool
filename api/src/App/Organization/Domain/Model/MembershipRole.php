<?php

declare(strict_types=1);

namespace App\Organization\Domain\Model;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Employee = 'employee';

    /** Owners and managers change organization settings and run the business modules. */
    public function canManageOrganization(): bool
    {
        return self::Employee !== $this;
    }

    /** Owners give any role; managers add employees only; employees give none. */
    public function canAssign(self $role): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Manager => self::Employee === $role,
            self::Employee => false,
        };
    }
}
