<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListExpenseCategories;

use App\Shared\Application\Query\QueryInterface;

/**
 * The expense categories of the current organization (TenantFilter) for an owner or manager: active
 * ones, and the archived ones too when asked. Returns null when the actor is not an active member;
 * throws when the actor is an employee (ADR 0021).
 */
final readonly class ListExpenseCategoriesQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public bool $includeArchived = false,
    ) {
    }
}
