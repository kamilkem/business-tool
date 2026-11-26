<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\ListProductProposals;

use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Proposal\ProposalGroup;

/** One proposal: what the model proposes for a group of items, and the items' names to recognize it by. */
final readonly class ProductProposalView
{
    /**
     * @param list<string> $exampleNames
     */
    public function __construct(
        public string $id,
        public string $name,
        public ProductMeasure $measure,
        public ?string $categoryId,
        public int $itemCount,
        public array $exampleNames,
        /** The product that has this name now, when one was created after the model proposed it: the proposal is its other name. */
        public ?string $existingProductId,
    ) {
    }

    public static function fromGroup(ProposalGroup $group, ?string $existingProductId): self
    {
        return new self(ProposalGroup::idOf($group->key), $group->name, $group->measure, $group->categoryId, $group->itemCount, $group->exampleNames, $existingProductId);
    }
}
