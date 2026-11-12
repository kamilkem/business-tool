<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryExpenseCategoryRepository implements ExpenseCategoryRepositoryInterface
{
    /** @var array<string, ExpenseCategory> */
    private array $byId = [];

    public function findById(Uuid $id): ?ExpenseCategory
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByName(string $name): ?ExpenseCategory
    {
        foreach ($this->byId as $category) {
            if ($category->getName() === $name) {
                return $category;
            }
        }

        return null;
    }

    public function findAll(bool $includeArchived = false): array
    {
        $categories = array_values(array_filter($this->byId, static fn (ExpenseCategory $category): bool => $includeArchived || !$category->isArchived()));
        usort($categories, static fn (ExpenseCategory $a, ExpenseCategory $b): int => [$a->isArchived(), $a->getName()] <=> [$b->isArchived(), $b->getName()]);

        return $categories;
    }

    public function add(ExpenseCategory $category): void
    {
        $this->byId[$category->getId()->toRfc4122()] = $category;
    }

    public function save(ExpenseCategory $category): void
    {
    }
}
