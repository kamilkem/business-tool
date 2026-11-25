<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListProductCategories;

use App\Shared\Application\Query\QueryInterface;

/**
 * The product categories of the current organization (TenantFilter), flat with their parents'
 * ids, for an owner or manager. Returns null when the actor is not an active member; throws when
 * the actor is an employee (ADR 0021).
 */
final readonly class ListProductCategoriesQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
