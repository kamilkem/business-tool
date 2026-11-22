<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Uid\Uuid;

/**
 * Who may change Cash flow's lists: owners and managers (ADR 0033 §2, §3). Every active member may
 * read the active storages and denominations, because the end-of-day close needs them (§6). The
 * caller passes the role of an active member (ADR 0014).
 */
final class CashFlowAccess
{
    /** @throws CashFlowAccessForbiddenException when the role is employee */
    public static function assertCanManage(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw CashFlowAccessForbiddenException::toManage($actorId->toRfc4122());
        }
    }
}
