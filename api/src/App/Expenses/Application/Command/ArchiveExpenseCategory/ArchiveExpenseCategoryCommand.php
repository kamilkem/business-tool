<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\ArchiveExpenseCategory;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager archives an expense category: it is no longer offered for new expenses. */
final readonly class ArchiveExpenseCategoryCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
    ) {
    }
}
