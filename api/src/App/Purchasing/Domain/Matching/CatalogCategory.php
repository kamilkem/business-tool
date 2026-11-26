<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/** A category of the organization's tree as the interpreter sees it: its id and its path of names (`Alcohol > Spirits`). */
final readonly class CatalogCategory
{
    public function __construct(
        public string $id,
        public string $path,
    ) {
    }
}
