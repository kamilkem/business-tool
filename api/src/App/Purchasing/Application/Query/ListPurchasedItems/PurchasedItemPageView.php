<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListPurchasedItems;

use App\Purchasing\Application\Query\GetPurchasedItem\PurchasedItemView;

final readonly class PurchasedItemPageView
{
    /** @param list<PurchasedItemView> $items */
    public function __construct(
        public array $items,
        /** How many items the filters select in all pages. */
        public int $total,
        public int $page,
        public int $pageSize,
    ) {
    }
}
