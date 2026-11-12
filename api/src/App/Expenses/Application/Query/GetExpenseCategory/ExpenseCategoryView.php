<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetExpenseCategory;

use App\Expenses\Domain\Model\ExpenseCategory;

final readonly class ExpenseCategoryView
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $archived,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $archivedAt,
    ) {
    }

    public static function fromCategory(ExpenseCategory $category): self
    {
        return new self(
            $category->getId()->toRfc4122(),
            $category->getName(),
            $category->isArchived(),
            $category->getCreatedAt(),
            $category->getArchivedAt(),
        );
    }
}
