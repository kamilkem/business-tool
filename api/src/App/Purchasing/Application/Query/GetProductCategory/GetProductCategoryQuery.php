<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductCategory;

use App\Shared\Application\Query\QueryInterface;

/**
 * One product category of the current organization (TenantFilter), for an owner or manager.
 * Returns null when the category does not exist there or the actor is not an active member; throws
 * when the actor is an employee (ADR 0021).
 */
final readonly class GetProductCategoryQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $categoryId,
    ) {
    }
}
