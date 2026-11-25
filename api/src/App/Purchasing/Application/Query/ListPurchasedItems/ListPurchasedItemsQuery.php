<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListPurchasedItems;

use App\Shared\Application\Query\QueryInterface;

/**
 * A page of the purchased items of the current organization (TenantFilter), newest invoice issue
 * date first, for an owner or manager. Every filter that is set must hold: any of the statuses
 * (PurchasedItemStatus values), the product, an issue date from `from` to `to` (both `Y-m-d`,
 * inclusive, each optional) and a text the item's name contains, case-insensitively. Returns null
 * when the actor is not an active member; throws when the actor is an employee (ADR 0021).
 */
final readonly class ListPurchasedItemsQuery implements QueryInterface
{
    public const int DEFAULT_PAGE_SIZE = 30;
    public const int MAX_PAGE_SIZE = 100;

    /** @param list<string> $statuses */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public array $statuses = [],
        public ?string $productId = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $search = null,
        public int $page = 1,
        public int $pageSize = self::DEFAULT_PAGE_SIZE,
    ) {
    }
}
