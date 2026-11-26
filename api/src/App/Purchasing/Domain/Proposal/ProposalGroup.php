<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Proposal;

use App\Purchasing\Domain\Model\ProductMeasure;

/** The items that propose the same product (the same name key), and what to show for them. */
final readonly class ProposalGroup
{
    /** The id of a proposal in the API: its key with hyphens for spaces, which a URL can hold. */
    public static function idOf(string $key): string
    {
        return str_replace(' ', '-', $key);
    }

    /** The key a proposal's id stands for. */
    public static function keyOf(string $id): string
    {
        return str_replace('-', ' ', $id);
    }

    /** @param list<string> $exampleNames */
    public function __construct(
        public string $key,
        /** The spelling most items propose. */
        public string $name,
        /** What most items propose. */
        public ProductMeasure $measure,
        public ?string $categoryId,
        public int $itemCount,
        /** A few distinct names of the items, to recognize the product by. */
        public array $exampleNames,
    ) {
    }
}
