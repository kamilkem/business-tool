<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Who may see and change pay rates and payroll runs (ADR 0027 §10): owners and managers manage
 * everyone's rates, their own included, and prepare runs; only owners approve and reopen runs and
 * mark paychecks paid; employees read their own rates and paychecks only. The caller passes the role
 * of an active member (ADR 0014).
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

    /** @throws PayrollAccessForbiddenException when the role is employee */
    public static function assertCanPrepareRuns(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw PayrollAccessForbiddenException::toManageRuns($actorId->toRfc4122());
        }
    }

    /** Approving and reopening a run: owners only, for money that leaves (ADR 0027 §7, §9). */
    public static function assertCanApprove(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (MembershipRole::Owner !== $actorRole) {
            throw PayrollAccessForbiddenException::toApprove($actorId->toRfc4122());
        }
    }

    /** Marking paychecks paid and undoing the mark: owners only (ADR 0027 §9–10). */
    public static function assertCanPay(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (MembershipRole::Owner !== $actorRole) {
            throw PayrollAccessForbiddenException::toPay($actorId->toRfc4122());
        }
    }
}
