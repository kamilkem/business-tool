<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Exception;

/**
 * Owners and managers manage everyone's rates, their own included; employees only read their own
 * (ADR 0027 §10).
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
}
