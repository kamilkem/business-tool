<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Repository;

use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Model\ExpenseCategory;
use Symfony\Component\Uid\Uuid;

/** Expense categories of the current organization only (TenantFilter). */
interface ExpenseCategoryRepositoryInterface
{
    public function findById(Uuid $id): ?ExpenseCategory;

    /** The category with exactly this name, archived or not: for ExpenseCategory::create() and rename(). */
    public function findByName(string $name): ?ExpenseCategory;

    /**
     * Active categories first, then by name.
     *
     * @return list<ExpenseCategory>
     */
    public function findAll(bool $includeArchived = false): array;

    /** @throws ExpenseCategoryNameTakenException when a category with the name was stored in the meantime */
    public function add(ExpenseCategory $category): void;

    /**
     * Persists changes made to an already managed category.
     *
     * @throws ExpenseCategoryNameTakenException when a category with the new name was stored in the meantime
     */
    public function save(ExpenseCategory $category): void;
}
