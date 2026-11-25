<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Who may see and change purchasing data: owners and managers, reads and writes (ADR 0041 §1, ADR
 * 0021). The caller passes the role of an active member (ADR 0014). The aggregates check it on every
 * change; query handlers call it before returning anything.
 */
final class PurchasingAccess
{
    /** @throws PurchasingManagementForbiddenException when the role is employee */
    public static function assertCanManage(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw PurchasingManagementForbiddenException::forUser($actorId->toRfc4122());
        }
    }
}
