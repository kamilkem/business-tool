<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProduct;

use App\Shared\Application\Query\QueryInterface;

/**
 * One product of the current organization (TenantFilter), for an owner or manager. Returns null
 * when the product does not exist there or the actor is not an active member; throws when the actor
 * is an employee (ADR 0021).
 */
final readonly class GetProductQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
    ) {
    }
}
