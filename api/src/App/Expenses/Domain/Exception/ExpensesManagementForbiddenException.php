<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/** Only owners and managers read and change expenses and their categories (ADR 0021). */
final class ExpensesManagementForbiddenException extends ExpensesException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not manage expenses: only owners and managers do.', $userId));
    }
}
