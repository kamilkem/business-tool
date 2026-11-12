<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\RenameExpenseCategory;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager renames an expense category of the organization. */
final readonly class RenameExpenseCategoryCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
        public string $name,
    ) {
    }
}
