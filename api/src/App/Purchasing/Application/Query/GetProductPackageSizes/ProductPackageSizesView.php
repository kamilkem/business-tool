<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductPackageSizes;

use App\Purchasing\Domain\PackageSize\PackageSizeGroup;

final readonly class ProductPackageSizesView
{
    /** @param list<PackageSizeGroup> $groups */
    public function __construct(
        public string $productId,
        public array $groups,
    ) {
    }
}
