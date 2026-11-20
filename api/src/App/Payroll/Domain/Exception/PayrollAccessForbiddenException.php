<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/**
 * Owners and managers manage everyone's rates and prepare payroll runs; only owners approve and
 * reopen runs; employees only read their own rates and paychecks (ADR 0027 §10).
 */
final class PayrollAccessForbiddenException extends PayrollException
{
    public static function toManage(string $actorId): self
    {
        return new self(\sprintf('User "%s" may not change pay rates: only owners and managers do.', $actorId));
    }

    public static function toRead(string $actorId, string $userId): self
    {
        return new self(\sprintf('User "%s" may not see the pay rates of user "%s": employees see their own only.', $actorId, $userId));
    }

    public static function toManageRuns(string $actorId): self
    {
        return new self(\sprintf('User "%s" may not prepare payroll runs: only owners and managers do.', $actorId));
    }

    public static function toApprove(string $actorId): self
    {
        return new self(\sprintf('User "%s" may not approve or reopen payroll runs: only owners do.', $actorId));
    }
}
