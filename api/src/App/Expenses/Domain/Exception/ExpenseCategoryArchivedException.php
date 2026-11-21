<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Exception;

/** An archived category is not offered for new expenses: it cannot be newly assigned (an invoice that has it keeps it). */
final class ExpenseCategoryArchivedException extends ExpensesException
{
    public static function of(string $name): self
    {
        return new self(\sprintf('The expense category "%s" is archived: restore it to assign it.', $name), ['name' => $name]);
    }
}
