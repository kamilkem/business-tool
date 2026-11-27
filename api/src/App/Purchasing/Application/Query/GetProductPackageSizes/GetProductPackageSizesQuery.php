<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductPackageSizes;

use App\Shared\Application\Query\QueryInterface;

/**
 * The items of a product of the current organization by unit and package size (ADR 0049), for an owner or
 * manager: what the dialog that sets a package size asks about. Returns null when the actor is not an active
 * member or the product is not the organization's; throws when the actor is an employee (ADR 0021).
 */
final readonly class GetProductPackageSizesQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
    ) {
    }
}
