<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Uid\Uuid;

/**
 * Who may see and change expenses: owners and managers, reads and writes (ADR 0021). The caller
 * passes the role of an active member (ADR 0014). The aggregates check it on every change; query
 * handlers call it before returning anything.
 */
final class ExpensesAccess
{
    /** @throws ExpensesManagementForbiddenException when the role is employee */
    public static function assertCanManage(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw ExpensesManagementForbiddenException::forUser($actorId->toRfc4122());
        }
    }
}
