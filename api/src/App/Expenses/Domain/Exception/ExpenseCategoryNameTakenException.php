<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/** Expense category names are unique within an organization, archived categories included. */
final class ExpenseCategoryNameTakenException extends ExpensesException
{
    public static function of(string $name): self
    {
        return new self(\sprintf('The organization already has an expense category named "%s".', $name));
    }
}
