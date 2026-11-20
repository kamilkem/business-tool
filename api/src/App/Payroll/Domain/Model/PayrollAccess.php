<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Who may see and change pay rates (ADR 0027 §10): owners and managers manage everyone's, their own
 * included; employees read their own only. The caller passes the role of an active member (ADR 0014).
 * Fixed in code for now; a per-organization setting is a later feature (#139).
 */
final class PayrollAccess
{
    /** @throws PayrollAccessForbiddenException when the role is employee */
    public static function assertCanManage(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw PayrollAccessForbiddenException::toManage($actorId->toRfc4122());
        }
    }

    /** @throws PayrollAccessForbiddenException when an employee asks about someone else */
    public static function assertCanRead(Uuid $actorId, MembershipRole $actorRole, Uuid $userId): void
    {
        if (!$actorRole->canManageOrganization() && !$actorId->equals($userId)) {
            throw PayrollAccessForbiddenException::toRead($actorId->toRfc4122(), $userId->toRfc4122());
        }
    }
}
