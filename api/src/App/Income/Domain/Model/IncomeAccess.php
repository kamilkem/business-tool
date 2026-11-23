<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RevenueMonthCloseForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Uid\Uuid;

/**
 * Who may see and change income: owners and managers, for registers and entries, reads and writes
 * (ADR 0022 §7). The caller passes the role of an active member (ADR 0014). The aggregates check it
 * on every change; query handlers (#43) call it before returning anything.
 */
final class IncomeAccess
{
    /** @throws IncomeManagementForbiddenException when the role is employee */
    public static function assertCanManage(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw IncomeManagementForbiddenException::forUser($actorId->toRfc4122());
        }
    }

    /** @throws RevenueMonthCloseForbiddenException when the role is not owner (ADR 0034 §1) */
    public static function assertCanCloseMonths(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (MembershipRole::Owner !== $actorRole) {
            throw RevenueMonthCloseForbiddenException::forUser($actorId->toRfc4122());
        }
    }
}
