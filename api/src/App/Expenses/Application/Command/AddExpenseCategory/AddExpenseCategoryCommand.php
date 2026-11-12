<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\AddExpenseCategory;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager adds an expense category to the organization. */
final readonly class AddExpenseCategoryCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
        public string $name,
    ) {
    }
}
