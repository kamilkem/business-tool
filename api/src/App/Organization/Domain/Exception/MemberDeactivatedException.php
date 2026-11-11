<?php

declare(strict_types=1);

namespace App\Organization\Domain\Exception;

/** The person's membership is deactivated: it must be reactivated before anything else is done with it. */
final class MemberDeactivatedException extends OrganizationException
{
    public static function of(string $userId, string $organizationId): self
    {
        return new self(\sprintf('User "%s" is a deactivated member of organization "%s". Reactivate them first.', $userId, $organizationId));
    }
}
