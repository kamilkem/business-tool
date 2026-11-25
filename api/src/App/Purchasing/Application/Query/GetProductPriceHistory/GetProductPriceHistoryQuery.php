<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductPriceHistory;

use App\Shared\Application\Query\QueryInterface;

/**
 * The price points of a product of the current organization issued from `from` to `to` (`Y-m-d`,
 * inclusive, each optional, no length limit), oldest first, for an owner or manager (ADR 0041 §7).
 * Returns null when the actor is not an active member or the product does not exist; throws when the
 * actor is an employee (ADR 0021) or a date is not valid.
 */
final readonly class GetProductPriceHistoryQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
        public ?string $from = null,
        public ?string $to = null,
    ) {
    }
}
