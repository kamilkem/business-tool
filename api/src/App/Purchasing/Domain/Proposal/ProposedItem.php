<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Proposal;

use App\Purchasing\Domain\Model\ProductMeasure;

/** What a proposal groups: one purchased item with a proposal, by its own name and the model's proposal for it (ADR 0044). */
final readonly class ProposedItem
{
    public function __construct(
        public string $itemId,
        public string $itemName,
        public string $proposedName,
        /** PurchasedItem::getProposedNameKey(). */
        public string $key,
        public ProductMeasure $measure,
        public ?string $categoryId,
    ) {
    }
}
