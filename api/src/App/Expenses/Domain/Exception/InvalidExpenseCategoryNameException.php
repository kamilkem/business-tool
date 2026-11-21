<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

final class InvalidExpenseCategoryNameException extends ExpensesException
{
    public static function of(string $name, int $maxLength): self
    {
        return new self(\sprintf('An expense category name must have 1 to %d characters, "%s" given.', $maxLength, $name), ['name' => $name, 'maxLength' => $maxLength]);
    }
}
