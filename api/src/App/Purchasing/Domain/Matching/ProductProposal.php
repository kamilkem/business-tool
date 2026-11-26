<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

use App\Purchasing\Domain\Model\ProductMeasure;

/**
 * What the interpreter proposes for an item no product of the catalog is (ADR 0044): a name, what the
 * product is counted in and a category of the organization's tree, by id. It is a proposal only: the
 * product exists when a person creates it.
 */
final readonly class ProductProposal
{
    public function __construct(
        public string $name,
        public ProductMeasure $measure,
        /** A CatalogCategory id; null when none of the categories fits. */
        public ?string $categoryId,
    ) {
    }
}
