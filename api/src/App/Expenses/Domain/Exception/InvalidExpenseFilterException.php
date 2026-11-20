<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/** The filters of an expense list contradict each other. */
final class InvalidExpenseFilterException extends ExpensesException
{
    public static function categoryAndUncategorized(): self
    {
        return new self('Filter either by a category or by uncategorized expenses, not both.');
    }
}
