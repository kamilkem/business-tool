<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Secret\Domain\Exception\SecretManagementForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Only an active owner sets or removes a secret (ADR 0026 §5, ADR 0023 §2). The caller passes the
 * role of an active member (ADR 0014); a non-member is reported as not found before this point.
 */
final class SecretAccess
{
    /** @throws SecretManagementForbiddenException when the role is not owner */
    public static function assertCanManage(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (MembershipRole::Owner !== $actorRole) {
            throw SecretManagementForbiddenException::forUser($actorId->toRfc4122());
        }
    }
}
