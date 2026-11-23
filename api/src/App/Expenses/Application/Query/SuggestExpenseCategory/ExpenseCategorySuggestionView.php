<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\SuggestExpenseCategory;

use App\Expenses\Domain\Model\ExpenseCategory;

/** The suggested category of a seller, or none (both null). */
final readonly class ExpenseCategorySuggestionView
{
    public function __construct(
        public ?string $categoryId,
        public ?string $categoryName,
    ) {
    }

    public static function of(?ExpenseCategory $category): self
    {
        return new self($category?->getId()->toRfc4122(), $category?->getName());
    }
}
