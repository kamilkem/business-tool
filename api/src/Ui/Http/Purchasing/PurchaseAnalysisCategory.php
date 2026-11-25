<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use App\Purchasing\Domain\Analysis\CategorySpend;

/** One category of the tree (a flat list with parents) with what was spent on it in the period. */
final readonly class PurchaseAnalysisCategory
{
    public function __construct(
        public string $id,
        public string $name,
        /** The parent category; null at the top. */
        public ?string $parentId,
        /** On the products directly in this category. */
        public PurchaseAnalysisAmounts $own,
        /** On this category and every subcategory, at any depth. */
        public PurchaseAnalysisAmounts $total,
    ) {
    }

    public static function fromDomain(CategorySpend $category): self
    {
        return new self(
            $category->id,
            $category->name,
            $category->parentId,
            PurchaseAnalysisAmounts::fromDomain($category->own),
            PurchaseAnalysisAmounts::fromDomain($category->total),
        );
    }
}
