<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/** No such expense category in this organization (another organization's is not found either). */
final class ExpenseCategoryNotFoundException extends ExpensesException
{
    public static function withId(string $categoryId): self
    {
        return new self(\sprintf('Expense category "%s" not found.', $categoryId));
    }
}
