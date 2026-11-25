<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListProducts;

use App\Shared\Application\Query\QueryInterface;

/**
 * The products of the current organization (TenantFilter), by name, for an owner or manager: all of
 * them, or only those in a category or any of its subcategories (at any depth), and/or whose name
 * contains a text (case-insensitively). Returns null when the actor is not an active member; throws
 * when the actor is an employee (ADR 0021). A category that does not exist has no products.
 */
final readonly class ListProductsQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public ?string $categoryId = null,
        public ?string $search = null,
    ) {
    }
}
