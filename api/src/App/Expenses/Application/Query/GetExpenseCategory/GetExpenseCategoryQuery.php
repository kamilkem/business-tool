<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetExpenseCategory;

use App\Shared\Application\Query\QueryInterface;

/**
 * One expense category of the current organization (TenantFilter), active or archived, for an owner
 * or manager. Returns null when the category does not exist there or the actor is not an active
 * member; throws when the actor is an employee (ADR 0021).
 */
final readonly class GetExpenseCategoryQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
    ) {
    }
}
