<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** Only owners and managers read and record income and its registers (ADR 0022 §7). */
final class IncomeManagementForbiddenException extends IncomeException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not manage income: only owners and managers do.', $userId), ['userId' => $userId]);
    }
}
