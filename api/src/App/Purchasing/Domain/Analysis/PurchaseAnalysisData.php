<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Analysis;

/**
 * The overview of a period as the database aggregates it (ADR 0041 §8). Only items of invoices in
 * the organization's currency and of products that are not excluded count; the rest is counted in
 * the `left out` figures.
 */
final readonly class PurchaseAnalysisData
{
    /**
     * @param list<CategorySpend> $categories every category, by name
     * @param list<ProductSpend>  $products   the products with items in the period, by name
     */
    public function __construct(
        public array $categories,
        public array $products,
        /** Spend of the items that are not linked to a product. */
        public AnalysisAmounts $notAssignedSpend,
        public int $notAssignedItems,
        /** Items of another currency than the organization's. */
        public int $otherCurrencyItems,
        public int $itemsWithoutNet,
        public int $itemsWithoutGross,
        /** Items of products with a unit whose package size is unknown, so they are no price points; correction rows excepted. */
        public int $itemsWithoutPackageSize,
    ) {
    }
}
