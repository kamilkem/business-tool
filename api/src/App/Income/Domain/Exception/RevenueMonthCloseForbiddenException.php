<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** Only owners close and reopen an Income month (ADR 0034 §1). */
final class RevenueMonthCloseForbiddenException extends IncomeException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not close or reopen a month: only owners do.', $userId), ['userId' => $userId]);
    }
}
