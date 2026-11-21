<?php

declare(strict_types=1);

namespace App\Reporting\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Reporting\Domain\Exception\BusinessSummaryForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Who may read the organization's business figures: owners and managers (ADR 0029 §3, ADR 0021).
 * The caller passes the role of an active member (ADR 0014).
 */
final class ReportingAccess
{
    /** @throws BusinessSummaryForbiddenException when the role is employee */
    public static function assertCanRead(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw BusinessSummaryForbiddenException::forUser($actorId->toRfc4122());
        }
    }
}
