<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/** A product of the organization's catalog as the interpreter sees it: what it is called, not what was spent on it. */
final readonly class CatalogProduct
{
    public function __construct(
        public string $id,
        public string $name,
        /** A ProductMeasure value. */
        public string $measure,
        public ?string $categoryName,
    ) {
    }
}
