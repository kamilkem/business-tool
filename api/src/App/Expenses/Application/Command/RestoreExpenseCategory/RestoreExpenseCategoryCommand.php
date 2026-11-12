<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\RestoreExpenseCategory;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager offers an archived expense category again. */
final readonly class RestoreExpenseCategoryCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
    ) {
    }
}
